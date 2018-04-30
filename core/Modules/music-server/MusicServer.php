<?php

namespace esc\Modules\MusicServer;

use Carbon\Carbon;
use esc\Classes\File;
use esc\Classes\Hook;
use esc\Classes\Log;
use esc\Classes\ManiaLinkEvent;
use esc\Classes\RestClient;
use esc\Classes\Server;
use esc\Classes\Template;
use esc\Controllers\ChatController;
use esc\Models\Player;
use esc\Models\Song;
use Illuminate\Support\Collection;

class MusicServer
{
    private static $startLoad;
    private static $music;
    private static $currentSong;
    private static $songQueue;

    public function __construct()
    {
        $this->readFiles();

        self::$songQueue = new Collection();

        ManiaLinkEvent::add('ms.hidemenu', 'MusicServer::hideMusicMenu');
        ManiaLinkEvent::add('ms.juke', 'MusicServer::queueSong');
        ManiaLinkEvent::add('ms.play', 'MusicServer::playSong');
        ManiaLinkEvent::add('music.next', 'MusicServer::nextSong');
        ManiaLinkEvent::add('ms.menu.showpage', 'MusicServer::displayMusicMenu');

        ChatController::addCommand('music', 'MusicServer::displayMusicMenu', 'Opens the music menu where you can queue music.');

        Hook::add('EndMatch', 'MusicServer::setNextSong');
        Hook::add('BeginMap', 'MusicServer::beginMap');
        Hook::add('PlayerConnect', 'MusicServer::displaySongWidget');
    }

    public static function onConfigReload()
    {
        self::displaySongWidget();
    }

    /**
     * Show music widget on map start
     * @param array ...$args
     */
    public static function beginMap(...$args)
    {
        self::$currentSong = self::$music->random();
        self::displaySongWidget();
    }

    /**
     * Gets current song
     * @return null|Song
     */
    public static function getCurrentSong(): ?Song
    {
        $url = self::$currentSong->url;
        return self::$music->where('url', $url)->first();
    }

    /**
     * Sets next song to be played
     * @param array ...$args
     */
    public static function setNextSong(...$args)
    {
        Server::setForcedMusic(true, 'https://ozonic.co.uk/empty.ogg');
    }

    /**
     * Display the onscreen widget
     * @param Player|null $player
     */
    public static function displaySongWidget(Player $player = null)
    {
        if (!self::$music) {
            Log::warning("Music not loaded, can not display widget.");
            return;
        }

        $song = self::$currentSong;

        if ($song) {
            $lengthInSeconds = self::getTrackLengthInSeconds($song);

            if ($player) {
                self::showWidget($player, $song, $lengthInSeconds);
            } else {
                onlinePlayers()->each(function (Player $player) use ($song, $lengthInSeconds) {
                    self::showWidget($player, $song, $lengthInSeconds);
                });
            }

            self::$currentSong = $song;
        } else {
            Log::error("Invalid song");
        }
    }

    private static function showWidget(Player $player, $song, $lengthInSeconds)
    {
        $content = Template::toString('music-server.music', [
            'song' => $song,
            'lengthInSeconds' => $lengthInSeconds,
            'config' => config('ui.music'),
            'hideSpeed' => $player->user_settings->ui->hideSpeed ?? null
        ]);

        Template::show($player, 'components.icon-box', [
            'id' => 'music-widget',
            'content' => $content,
            'config' => config('ui.music')
        ]);
    }

    /**
     * Display the music menu
     * @param Player $player
     * @param int $page
     */
    public static function displayMusicMenu(Player $player, $page = null)
    {
        $perPage = 19;
        $songsCount = self::$music->count();
        $songs = self::$music->sortBy('title')->forPage($page ?? 0, $perPage);

        $queue = self::$songQueue->sortBy('time')->take(9);

        $music = Template::toString('music-server.menu', ['songs' => $songs, 'queue' => $queue]);
        $pagination = Template::toString('components.pagination', ['pages' => ceil($songsCount / $perPage), 'action' => 'ms.menu.showpage', 'page' => $page]);

        Template::show($player, 'components.modal', [
            'id' => '♫ Music',
            'width' => 180,
            'height' => 97,
            'content' => $music,
            'pagination' => $pagination,
            'showAnimation' => isset($page) ? false : true
        ]);
    }

    /**
     * Hides the music menu
     * @param Player $triggerer
     */
    public static function hideMusicMenu(Player $triggerer)
    {
        Template::hide($triggerer, 'music-server.menu');
    }

    /**
     * Adds a song to the music-jukebox
     * @param Player $callee
     * @param $songId
     */
    public static function queueSong(Player $callee, $songId)
    {
        $song = self::$music->get($songId);

        if ($song) {
            self::$songQueue->push([
                'song' => $song,
                'wisher' => $callee,
                'time' => time()
            ]);

            ChatController::messageAll($callee, ' added song ', secondary($song->title ?: ''), ' to the jukebox');
        }

        Template::hide($callee, 'music-server.menu');
    }

    /**
     * Plays a song
     * @param Player $callee
     * @param $songId
     */
    public static function playSong(Player $callee, $songId)
    {
        $song = self::$music->get($songId);

        if ($song) {
            self::$currentSong = $song;
            self::displaySongWidget($callee);
        }
    }

    /**
     * Goes to next song
     * @param Player $callee
     * @param $songId
     */
    public static function nextSong(Player $callee)
    {
        $song = self::$music->random();

        if ($song) {
            self::$currentSong = $song;
            self::displaySongWidget($callee);
        }
    }

    /**
     * Sets the music files
     * @param Collection $songs
     */
    private static function setMusicFiles(Collection $songs)
    {
        $url = preg_replace('/\/\?token=[\w]+/', '', config('music.server'));

        $songs->each(function (&$song) use ($url) {
            $song->url = $url . '/' . $song->file;
        });

        Log::info("Finished loading music.");

        self::$music = $songs;
        self::$currentSong = $songs->random();
    }

    /**
     * Read available music form server
     */
    private function readFiles()
    {
        Log::info("Loading music...");

        if (File::exists(cacheDir('music.json'))) {
            $musicJson = file_get_contents(cacheDir('music.json'));
        }else{
            $res = RestClient::get(config('music.server'));
            $musicJson = $res->getBody()->getContents();

            $musicData = collect([
                'date' => Carbon::now(),
                'data' => $musicJson
            ]);

            File::put(cacheDir('music.json'), $musicData->toJson());
        }

        try {
            $musicFiles = json_decode($musicJson);
            MusicServer::setMusicFiles(collect($musicFiles));
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::logAddLine('Music server', 'Failed to get music, make sure you have the url and token set', true);
        }
    }

    private static function getTrackLengthInSeconds($song)
    {
        if (preg_match('/(\d+):(\d+)/', $song->length ?? '', $matches)) {
            return intval($matches[1]) * 60 + intval($matches[2]);
        }

        return 0;
    }
}