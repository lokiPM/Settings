<?php

namespace lokiPM\Settings;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\form\Form;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\utils\Config;
use pocketmine\scheduler\Task;

class Main extends PluginBase implements Listener {

    private $toggleState = [];
    private $scoreboardState = [];
    private $cpsPopupState = [];
    private $clickTimestamps = [];
    private $config;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->loadConfig();

        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private $plugin;

            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                    $playerName = $player->getName();
                    if ($this->plugin->getCpsPopupState($playerName)) {
                        $cps = $this->plugin->calculateCps($playerName);
                        $player->sendActionBarMessage("§cCPS: §b$cps");
                    }
                }
            }
        }, 20);
    }

    private function loadConfig(): void {
        $this->saveResource("settings.yml");
        $this->config = new Config($this->getDataFolder() . "settings.yml", Config::YAML);

        $this->toggleState = $this->config->get("toggleState", []);
        $this->scoreboardState = $this->config->get("scoreboardState", []);
        $this->cpsPopupState = $this->config->get("cpsPopupState", []);
    }

    public function saveConfig(): void {
        $this->config->set("toggleState", $this->toggleState);
        $this->config->set("scoreboardState", $this->scoreboardState);
        $this->config->set("cpsPopupState", $this->cpsPopupState);
        $this->config->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "settings" && $sender instanceof Player && $sender->hasPermission("settings.menu")) {
            $playerName = $sender->getName();
            $form = new class($this, $playerName) implements Form {
                private $plugin;
                private $playerName;

                public function __construct(Main $plugin, string $playerName) {
                    $this->plugin = $plugin;
                    $this->playerName = $playerName;
                }

                public function handleResponse(Player $player, $data): void {
                    if ($data === null) return;

                    if (isset($data[0])) {
                        $this->plugin->setToggleState($this->playerName, (bool)$data[0]);
                    }

                    if (isset($data[1])) {
                        $this->plugin->setCpsPopupState($this->playerName, (bool)$data[1]);
                    }

                    if (isset($data[2])) {
                        $newState = (bool)$data[2];
                        $currentState = $this->plugin->getScoreboardState($this->playerName);
                        if ($newState !== $currentState) {
                            $this->plugin->setScoreboardState($this->playerName, $newState);
                            $this->plugin->handleScoreboardCommand($this->playerName, $newState);
                        }
                    }

                    $this->plugin->saveConfig();
                }

                public function jsonSerialize(): array {
                    return [
                        "type" => "custom_form",
                        "title" => "Settings",
                        "content" => [
                            [
                                "type" => "toggle",
                                "text" => "Auto Sprint",
                                "default" => $this->plugin->getToggleState($this->playerName)
                            ],
                            [
                                "type" => "toggle",
                                "text" => "CPS Popup",
                                "default" => $this->plugin->getCpsPopupState($this->playerName)
                            ],
                            [
                                "type" => "toggle",
                                "text" => "Scoreboard",
                                "default" => $this->plugin->getScoreboardState($this->playerName)
                            ]
                        ]
                    ];
                }
            };

            $sender->sendForm($form);
            return true;
        }
        return false;
    }

    public function setToggleState(string $playerName, bool $state): void {
        $this->toggleState[$playerName] = $state;
    }

    public function getToggleState(string $playerName): bool {
        return $this->toggleState[$playerName] ?? false;
    }

    public function setCpsPopupState(string $playerName, bool $state): void {
        $this->cpsPopupState[$playerName] = $state;
    }

    public function getCpsPopupState(string $playerName): bool {
        return $this->cpsPopupState[$playerName] ?? false;
    }

    public function setScoreboardState(string $playerName, bool $state): void {
        $this->scoreboardState[$playerName] = $state;
    }

    public function getScoreboardState(string $playerName): bool {
        return $this->scoreboardState[$playerName] ?? true;
    }

    public function handleScoreboardCommand(string $playerName, bool $state): void {
        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player instanceof Player) {
            $command = $state ? "scorehud on" : "scorehud off";
            $this->getServer()->dispatchCommand($player, $command);
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();

        if ($this->getToggleState($playerName)) {
            $from = $event->getFrom();
            $to = $event->getTo();

            if ($from->distanceSquared($to) > 0) {
                $player->setSprinting(true);
            } else {
                $player->setSprinting(false);
            }
        }
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();

        if ($player instanceof Player && $packet instanceof InventoryTransactionPacket) {
            $transaction = $packet->trData;

            if ($transaction instanceof UseItemOnEntityTransactionData) {
                $playerName = $player->getName();
                if ($this->getCpsPopupState($playerName)) {
                    $this->recordClick($playerName);
                }
            }
        }
    }

    public function recordClick(string $playerName): void {
        $currentTime = microtime(true);
        $this->clickTimestamps[$playerName][] = $currentTime;

        $this->clickTimestamps[$playerName] = array_filter(
            $this->clickTimestamps[$playerName] ?? [],
            fn($timestamp) => ($currentTime - $timestamp) <= 1.0
        );
    }

    public function calculateCps(string $playerName): int {
        $currentTime = microtime(true);
        $this->clickTimestamps[$playerName] = array_filter(
            $this->clickTimestamps[$playerName] ?? [],
            fn($timestamp) => ($currentTime - $timestamp) <= 1.0
        );
        return count($this->clickTimestamps[$playerName] ?? []);
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $playerName = $event->getPlayer()->getName();
    }

    public function onDisable(): void {
        $this->saveConfig();
    }
}