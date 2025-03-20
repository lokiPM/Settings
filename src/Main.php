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
                        $currentState =