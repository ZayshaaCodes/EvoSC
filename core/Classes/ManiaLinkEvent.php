<?php

namespace EvoSC\Classes;


use EvoSC\Exceptions\UnauthorizedException;
use EvoSC\Models\Player;
use EvoSC\Modules\QuickButtons\QuickButtons;
use Exception;
use Illuminate\Support\Collection;

/**
 * Class ManiaLinkEvent
 *
 * Handle actions send from ManiaScripts (clients).
 *
 * @package EvoSC\Classes
 */
class ManiaLinkEvent
{
    /**
     * @var Collection
     */
    private static Collection $maniaLinkEvents;

    /**
     * @var Collection
     */
    private static Collection $extendedMLE;

    public string $id;

    /**
     * @var callable
     */
    public $callback;
    public ?string $access;

    /**
     * Initialize ManiaLinkEvent
     */
    public static function init()
    {
        self::$maniaLinkEvents = collect();
        self::$extendedMLE = collect();

        Hook::add('PlayerManialinkPageAnswer', [self::class, 'call']);
        ManiaLinkEvent::add('mle', [self::class, 'maniaLinkExtended']);
    }

    /**
     * ManiaLinkEvent constructor.
     *
     * @param string $id
     * @param callable|array $callback
     * @param string $access
     */
    private function __construct(string $id, $callback, string $access = null)
    {
        $this->id = $id;
        $this->callback = $callback;
        $this->access = $access;
    }

    /**
     * Get all registered mania link events.
     *
     * @return Collection
     */
    private static function getManiaLinkEvents(): Collection
    {
        return self::$maniaLinkEvents;
    }

    /**
     * Add a manialink event. Callback must be of type [MyClass::class, 'methodToCall'].
     *
     * @param string $id
     * @param $callback
     * @param string|null $access
     * @return ManiaLinkEvent
     */
    public static function add(string $id, $callback, string $access = null): ManiaLinkEvent
    {
        $maniaLinkEvents = self::getManiaLinkEvents();

        $event = new ManiaLinkEvent(strtolower($id), $callback, $access);

        $existingEvents = $maniaLinkEvents->where('id', strtolower($id));
        if ($existingEvents->isNotEmpty()) {
            self::$maniaLinkEvents = self::$maniaLinkEvents->diff($existingEvents);
        }

        $maniaLinkEvents->push($event);
        return $event;
    }

    /**
     * @param Player $player
     * @param $gameTime
     * @param $action
     * @param $i
     * @param $isFinished
     * @param mixed ...$body
     */
    public static function maniaLinkExtended(Player $player, $gameTime, $action, $i, $isFinished, ...$body)
    {
        if (!self::$extendedMLE->has($gameTime)) {
            self::$extendedMLE->put($gameTime, collect());
        }

        self::$extendedMLE->get($gameTime)->put($i, implode(',', $body));

        if ($isFinished == '1') {
            self::call($player, $action . ',' . self::$extendedMLE->get($gameTime)->implode(''));
            self::$extendedMLE->forget($gameTime);
        }
    }

    /**
     * Handle an ingoing mania-link event.
     *
     * @param Player $ply
     * @param string $action
     * @param array|null $formValues
     */
    public static function call(Player $ply, string $action, array $formValues = null)
    {
        $action = trim($action);

        if ($action == '') {
            return;
        }

        if (isVerbose()) {
            Log::write("$action", false);
        }

        if (preg_match('/^(.+)::(.+?),/', $action, $matches)) {
            $callback = [$matches[1], $matches[2]];
        } else if (preg_match('/(\w+[.\w]+)*(?:,[\d\w ]+)*/', $action, $matches)) {
            $event = self::getManiaLinkEvents()->where('id', $matches[1])->first();

            if (!$event) {
                Log::warning("Calling undefined ManiaLinkEvent $action.");

                return;
            }

            if ($event->access != null && !$ply->hasAccess($event->access)) {
                warningMessage('Sorry, you\'re not allowed to do that.')->send($ply);
                Log::write('Player ' . $ply . ' tried to access forbidden ManiaLinkEvent: ' . $event->id . ' -> ' . implode('::',
                        $event->callback));

                return;
            }

            $callback = $event->callback;
        } else {
            Log::warning("Malformed ManiaLinkEvent $action.");

            return;
        }

        $arguments = explode(',', $action);
        $arguments[0] = $ply;

        if ($formValues) {
            $formValuesObject = new \stdClass();
            foreach ($formValues as $value) {
                $formValuesObject->{$value['Name']} = $value['Value'];
            }
            array_push($arguments, $formValuesObject);
        }

        try {
            call_user_func_array($callback, $arguments);
        } catch (UnauthorizedException $e) {
            warningMessage('Sorry, you\'re not allowed to do that.')->send($ply);
            Log::warning($e->getMessage());
        } catch (Exception $e) {
            Log::error("An error occured calling " . $callback[0] . '::' . $callback[1] . ": " . $e->getMessage());
            Log::write($e->getTraceAsString(), isVerbose());
        }
    }

    public static function removeAll()
    {
        self::$maniaLinkEvents = collect();
    }

    public function __toString()
    {
        return $this->id . '(' . serialize($this->callback) . ')';
    }

    public function withScoreTableButton(string $icon, string $name)
    {
        QuickButtons::addButton($icon, $name, $this->id, $this->access);
    }
}