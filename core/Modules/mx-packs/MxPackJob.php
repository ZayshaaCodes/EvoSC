<?php


namespace esc\Classes;


use esc\Controllers\MapController;
use esc\Controllers\MatchSettingsController;
use esc\Models\Map;
use esc\Models\Player;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use ZipArchive;

class MxPackJob
{
    private $id;
    private $files;
    private $name;
    private $path;
    private $packsDir;
    private $info;
    private $i = 0;

    /**
     * @var Player
     */
    private $issuer;

    public function __construct(Player $player, $packId)
    {
        $this->packsDir = MapController::getMapsPath('MXPacks');

        if (!is_dir($this->packsDir)) {
            mkdir($this->packsDir);
        }

        $this->info = Cache::get("map-packs/".$packId."_info");
        $this->name = $this->info->ID.'_'.$this->info->Shortname;
        $this->path = $this->packsDir.'/'.$this->name.'.zip';
        $this->issuer = $player;
        $this->id = $packId;

        try {
            $this->loadFiles();
        } catch (\Exception $e) {
            warningMessage('Failed to download map pack: ', secondary($e->getMessage()))->send($player);
        } catch (GuzzleException $e) {
            warningMessage('Failed to download map pack: ', secondary($e->getMessage()))->send($player);
        }
    }

    /**
     * @param $info
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    private function loadFiles()
    {
        if (File::exists($this->path)) {
            $this->unpackFiles($this->path);
            return;
        }

        $url = sprintf('https://tm.mania-exchange.com/mappack/download/%s?%s', $this->info->ID, $this->info->Secret);

        $response = RestClient::get($url);

        if ($response->getStatusCode() != 200) {
            warningMessage('Failed to download map pack ', secondary($this->info->Name))->send($this->issuer);

            return;
        }

        File::put($this->path, $response->getBody()->getContents());

        $this->unpackFiles($this->path);
    }

    /**
     * @param  string  $path
     * @throws \Exception
     */
    private function unpackFiles(string $path)
    {
        $zip = new ZipArchive();
        $dir = $this->packsDir.'/'.$this->name;

        if (is_dir($dir)) {
            $this->addFiles(File::getFiles($dir));
            return;
        }

        mkdir($dir);

        if ($zip->open($path) === true) {
            $zip->extractTo($dir);
            $zip->close();
        } else {
            throw new \Exception('Failed to unzip archive.');
        }

        $this->addFiles(File::getFiles($dir));
    }

    private function addFiles(Collection $files)
    {
        $files->each(function ($value) {
            $name = basename($value);
            preg_match('/\((\d+)\)\.Gbx$/', $name, $matches);
            $mx_id = $matches[1];
            $filename = 'MXPacks/'.$this->name.'/'.$name;

            $gbx = MapController::getGbxInformation($filename, false);
            $uid = $gbx->MapUid;
            $authorLogin = $gbx->AuthorLogin;

            if (!Map::whereUid($uid)->exists()) {
                if (Player::whereLogin($authorLogin)->exists()) {
                    $authorId = Player::find($authorLogin)->id;
                } else {
                    $authorId = Player::insertGetId([
                        'NickName' => $gbx->AuthorNick,
                        'Login' => $authorLogin
                    ]);
                }

                Map::create([
                    'uid' => $uid,
                    'filename' => $filename,
                    'gbx' => json_encode($gbx),
                    'author' => $authorId,
                    'mx_id' => $mx_id,
                    'enabled' => 1
                ]);
            }

            $map = Map::whereUid($uid)->first();

            $map->update([
                'enabled' => 1
            ]);

            MatchSettingsController::addMapToCurrentMatchSettings($map);

            try {
                Server::addMap($filename);
            } catch (\Exception $e) {
                Log::write('MxPackJob', $e->getMessage());
            }
        });

        Hook::fire('MapPoolUpdated');

        infoMessage($this->issuer, ' added map-pack ',
            '$l[https://tm.mania-exchange.com/mappack/view/'.$this->id.']'.secondary($this->info->Name),
            ' from Mania-Exchange.')->sendAll();
    }
}