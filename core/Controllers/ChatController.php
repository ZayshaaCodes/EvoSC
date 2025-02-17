<?php

namespace EvoSC\Controllers;


use EvoSC\Classes\ChatCommand;
use EvoSC\Classes\Hook;
use EvoSC\Classes\Log;
use EvoSC\Classes\Question;
use EvoSC\Classes\Server;
use EvoSC\Interfaces\ControllerInterface;
use EvoSC\Models\AccessRight;
use EvoSC\Models\Player;
use Illuminate\Support\Collection;
use Maniaplanet\DedicatedServer\Xmlrpc\FaultException;

/**
 * Class ChatController
 *
 * Handle chat-messages and commands.
 *
 * @package EvoSC\Controllers
 */
class ChatController implements ControllerInterface
{
    /** @var boolean */
    private static bool $routingEnabled;

    private static string $primary;

    private static array $betterChatEnabledLogins = [];

    /**
     * Initialize ChatController.
     */
    public static function init()
    {
        AccessRight::add('player_mute', 'Mute/unmute player.');
        AccessRight::add('admin_echoes', 'Receive admin messages.');

        if ((self::$routingEnabled = (bool)config('server.enable-chat-routing', true))) {
            Log::info('Enabling manual chat routing.', isVerbose());

            try {
                Server::chatEnableManualRouting();
                Log::info('Chat router started.');
            } catch (FaultException $e) {
                Log::warningWithCause('Failed to enable manual chat routing', $e, isVerbose());
            }
        } else {
            Server::chatEnableManualRouting(false);
        }
    }

    /**
     * @param string $mode
     * @param bool $isBoot
     * @return mixed|void
     */
    public static function start(string $mode, bool $isBoot)
    {
        self::$primary = (string)config('theme.chat.default');

        ChatCommand::add('//mute', [self::class, 'cmdMute'], 'Mutes a player by given nickname', 'player_mute');
        ChatCommand::add('//unmute', [self::class, 'cmdUnmute'], 'Unmute a player by given nickname', 'player_mute');
        ChatCommand::add('/version', [self::class, 'cmdVersion'], 'Print server, client and EvoSC version.');
        ChatCommand::add('/chatformat', [self::class, 'cmdChatFormat'], 'Outputs chat-text as JSON format.');
        Hook::add('PlayerDisconnect', [self::class, 'playerDisconnect']);
    }

    /**
     * @param Player $player
     * @param $cmd
     */
    public static function cmdVersion(Player $player, $cmd)
    {
        infoMessage('$fffEvoSC-Version: ' . getEvoSCVersion())->send($player);
    }

    /**
     * @param Player $player
     * @param $cmd
     * @param $format
     */
    public static function cmdChatFormat(Player $player, $cmd, $format = null)
    {
        switch ($format) {
            case 'json':
                if (!in_array($player->Login, self::$betterChatEnabledLogins)) {
                    array_push(self::$betterChatEnabledLogins, $player->Login);
                }
                break;

            case 'text':
                if (in_array($player->Login, self::$betterChatEnabledLogins)) {
                    self::$betterChatEnabledLogins = array_diff(self::$betterChatEnabledLogins, [$player->Login]);
                }
                break;

            default:
                dangerMessage('Invalid chat format entered. Available formats are: ', secondary('json, text'))->send($player);
        }
    }

    /**
     * Mute a player
     *
     * @param Player $admin
     * @param Player $target
     */
    public static function mute(Player $admin, Player $target)
    {
        Server::ignore($target->Login);
        infoMessage($admin, ' muted ', $target)->sendAll();
    }

    /**
     * Unmute a player
     *
     * @param Player $player
     * @param Player $target
     */
    public static function unmute(Player $player, Player $target)
    {
        Server::unIgnore($target->Login);
        infoMessage($player, ' unmuted ', $target)->sendAll();
    }

    /**
     * Chat-command: unmute player.
     *
     * @param Player $player
     * @param                    $nick
     */
    public static function cmdUnmute(Player $player, $nick)
    {
        $target = PlayerController::findPlayerByName($player, $nick);

        if ($target) {
            self::unmute($player, $target);
        }
    }

    /**
     * Chat-command: mute player.
     *
     * @param Player $admin
     * @param                    $nick
     */
    public static function cmdMute(Player $admin, $nick)
    {
        $target = PlayerController::findPlayerByName($admin, $nick);

        if (!$target) {
            //No target found
            return;
        }

        self::mute($admin, $target);
    }

    public static function isPlayerMuted(Player $player)
    {
        return collect(Server::getIgnoreList())->contains('login', '=', $player->Login);
    }

    public static function pmTo(Player $player, $login, $message)
    {
        if (empty($message)) {
            return;
        }

        $target = player($login);

        if ($target->id == $player->id) {
            warningMessage('You can\'t PM yourself.')->send($player);

            return;
        }

        $from = secondary("[from:\$<$player\$>]");
        $to = secondary("[to:\$<$target\$>]");

        chatMessage($from . " \$<\$fff$message\$>")->setIcon('')->send($target);
        chatMessage($to . " \$<\$fff$message\$>")->setIcon('')->send($player);
    }

    /**
     * Process chat-message and detect commands.
     *
     * @param Player $player
     * @param string $text
     */
    public static function playerChat(Player $player, $text)
    {
        Log::write('<fg=yellow>[' . $player . '] ' . $text . '</>', true);

        $name = preg_replace('/(?:(?<=[^$])\$s|^\$s)/i', '', $player->NickName);
        $text = preg_replace('/(?:(?<=[^$])\$s|^\$s)/i', '', $text);

        if (strlen(trim(stripAll($text))) == 0) {
            return;
        }

        $text = trim($text);

        if ($player->isSpectator()) {
            $name = '$<$eee$> $fff' . $name;
        }

        $chatColor = self::$primary;
        if (empty($chatColor)) {
            $chatColor = '$z$s';
        } else {
            $chatColor = '$z$s$' . $chatColor;
        }

        $groupIcon = $player->group->chat_prefix ?? '';
        $groupColor = $player->group->color;
        $chatText = sprintf('$z$s$%s%s[$<%s$>]%s %s', $groupColor, $groupIcon, secondary($name), $chatColor, $text);
        $betterChatLogins = collect(self::$betterChatEnabledLogins);

        if ($betterChatLogins->isNotEmpty()) {
            $betterChatName = sprintf('$<$%s%s $<%s$>$>', $groupColor, $groupIcon, secondary($name));
            $allLogins = collect(Server::getPlayerList())->pluck('login');
            $punyChatLogins = $allLogins->diff($betterChatLogins);

            $jsonMessage = json_encode(['login' => $player->Login, 'nickname' => $betterChatName, 'text' => $text], JSON_UNESCAPED_UNICODE);
            Server::chatSendServerMessage('CHAT_JSON:' . $jsonMessage, $betterChatLogins->implode(','));

            if ($punyChatLogins->isNotEmpty()) {
                Server::chatSendServerMessage($chatText, $punyChatLogins->implode(','));
            }
        } else {
            Server::chatSendServerMessage($chatText);
        }

        Hook::fire('ChatLine', $chatText);
    }

    /**
     * @param string $message
     * @param Collection $recipientLogins
     */
    public static function sendServerMessage(string $message, Collection $recipientLogins)
    {
        $betterChatLogins = collect(self::$betterChatEnabledLogins)->intersect($recipientLogins);
        $punyChatLogins = $recipientLogins->diff($betterChatLogins);

        if ($betterChatLogins->isNotEmpty()) {
            $jsonMessage = json_encode(['text' => $message], JSON_UNESCAPED_UNICODE);
            Server::chatSendServerMessage('CHAT_JSON:' . $jsonMessage, $betterChatLogins->implode(','), true);
        }

        if ($punyChatLogins->isNotEmpty()) {
            Server::chatSendServerMessage($message, $punyChatLogins->implode(','), true);
        }

        Server::executeMulticall();
        Hook::fire('ChatLine', $message);
    }

    /**
     * @return mixed
     */
    public static function getRoutingEnabled()
    {
        return self::$routingEnabled;
    }

    /**
     * @return array
     */
    public static function getBetterChatEnabledLogins(): array
    {
        return self::$betterChatEnabledLogins;
    }

    /**
     * @param Player $player
     */
    public static function playerDisconnect(Player $player)
    {
        if (in_array($player->Login, self::$betterChatEnabledLogins)) {
            self::$betterChatEnabledLogins = array_diff(self::$betterChatEnabledLogins, [$player->Login]);
        }
    }
}
