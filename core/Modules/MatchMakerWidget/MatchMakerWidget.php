<?php


namespace EvoSC\Modules\MatchMakerWidget;


use EvoSC\Classes\Hook;
use EvoSC\Classes\ManiaLinkEvent;
use EvoSC\Classes\Module;
use EvoSC\Classes\Server;
use EvoSC\Classes\Template;
use EvoSC\Controllers\TeamController;
use EvoSC\Interfaces\ModuleInterface;
use EvoSC\Models\AccessRight;
use EvoSC\Models\Player;
use Illuminate\Support\Collection;

class MatchMakerWidget extends Module implements ModuleInterface
{
    /**
     * @inheritDoc
     */
    public static function start(string $mode, bool $isBoot = false)
    {
        AccessRight::add('match_maker', 'Control matches and view the admin panel for it.');

        ManiaLinkEvent::add('toggle_horns', [self::class, 'mleToggleHorns'], 'match_maker');
        ManiaLinkEvent::add('show_teams_setup', [self::class, 'mleShowTeamsSetup']);
        ManiaLinkEvent::add('setup_teams', [self::class, 'mleSetupTeams']);

        Hook::add('PlayerConnect', [self::class, 'showWidget']);

        if (!$isBoot) {
            foreach (accessPlayers('match_maker') as $player) {
                $hornsEnabled = !Server::areHornsDisabled();

                Template::show($player, 'MatchMakerWidget.widget', compact('hornsEnabled'));
            }
        }
    }

    /**
     * @param Player $player
     * @throws \EvoSC\Exceptions\InvalidArgumentException
     */
    public static function mleShowTeamsSetup(Player $player)
    {
        Template::show($player, 'MatchMakerWidget.team-setup');
    }

    /**
     * @param Player $player
     * @param \stdClass|null $data
     */
    public static function mleSetupTeams(Player $player, \stdClass $data = null)
    {
        Server::setForcedClubLinks(TeamController::getClubLinkUrl($data->name[0], $data->primary[0], $data->secondary[0]),
            TeamController::getClubLinkUrl($data->name[1], $data->primary[1], $data->secondary[1]));

        successMessage($player, ' updated the team information.')->sendAll();
    }

    /**
     * @param Player $player
     * @throws \EvoSC\Exceptions\InvalidArgumentException
     */
    public static function showWidget(Player $player)
    {
        if (!$player->hasAccess('match_maker')) {
            return;
        }

        $hornsEnabled = !Server::areHornsDisabled();

        Template::show($player, 'MatchMakerWidget.widget', compact('hornsEnabled'));
    }

    /**
     * @param Player $player
     */
    public static function mleToggleHorns(Player $player)
    {
        if (Server::areHornsDisabled()) {
            Server::disableHorns(false);
            successMessage($player, ' enabled horns, happy honking!')->sendAll();
        } else {
            Server::disableHorns(true);
            dangerMessage($player, ' disabled horns.')->sendAll();
        }
    }
}