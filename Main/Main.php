<?php

namespace MiniGame;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\Position;
use pocketmine\scheduler\ClosureTask;
use pocketmine\block\VanillaBlocks;

class Main extends PluginBase implements Listener {

    private array $players = [];
    private array $teams = ["red" => [], "blue" => []];
    private array $scores = ["red" => 0, "blue" => 0];
    private bool $gameStarted = false;
    private int $maxScore = 5;
    private int $gameDuration = 300; // 5 minutes (300 seconds)
    private Position $redSpawn;
    private Position $blueSpawn;
    private Position $flagRed;
    private Position $flagBlue;
    private array $powerUps = [];
    
    public function onEnable(): void {
        $this->getLogger()->info(TF::GREEN . "MiniGame Plugin Enabled!");

        // Tentukan world dan posisi arena
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        $this->redSpawn = new Position(100, 65, 100, $world); // Koordinat contoh, bisa diubah
        $this->blueSpawn = new Position(-100, 65, -100, $world);
        $this->flagRed = new Position(105, 65, 105, $world);
        $this->flagBlue = new Position(-105, 65, -105, $world);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onDisable(): void {
        $this->getLogger()->info(TF::RED . "MiniGame Plugin Disabled!");
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        if (!$this->gameStarted) {
            $this->assignTeam($player);
            $player->teleport($this->getPlayerTeam($player) === "red" ? $this->redSpawn : $this->blueSpawn);
            $player->sendMessage(TF::YELLOW . "You have joined the game! Team: " . $this->getPlayerTeam($player));
            $this->checkStartConditions();
        } else {
            $player->sendMessage(TF::RED . "The game has already started. Please wait for the next round.");
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        unset($this->players[$player->getName()]);
        $this->removeFromTeam($player);
        if (count($this->players) < 2 && $this->gameStarted) {
            $this->endGame();
        }
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $victim = $event->getPlayer();
        $victimTeam = $this->getPlayerTeam($victim);

        // Teleport the victim back to their spawn after 5 seconds
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($victim, $victimTeam): void {
            $victim->teleport($victimTeam === "red" ? $this->redSpawn : $this->blueSpawn);
            $victim->setHealth($victim->getMaxHealth());
            $victim->sendMessage(TF::GREEN . "You have respawned!");
        }), 20 * 5);
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            $victim = $event->getEntity();
            if ($damager instanceof Player && $victim instanceof Player) {
                if ($this->gameStarted) {
                    $damagerTeam = $this->getPlayerTeam($damager);
                    $victimTeam = $this->getPlayerTeam($victim);

                    if ($damagerTeam !== $victimTeam) {
                        if ($event->getFinalDamage() >= $victim->getHealth()) {
                            $event->cancel();
                            $this->onPlayerDeath(new PlayerDeathEvent($victim, [], $damager->getName() . " has killed you!"));
                        }
                    } else {
                        $damager->sendMessage(TF::RED . "You cannot damage your teammate!");
                        $event->cancel();
                    }
                }
            }
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        if ($this->gameStarted) {
            if ($this->isFlagBlock($block)) {
                $player->sendMessage(TF::RED . "You cannot place blocks near the flags!");
                $event->cancel();
            }
        }
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        if ($this->gameStarted) {
            if ($this->isFlagBlock($block)) {
                if ($this->getPlayerTeam($player) === "red" && $block->getPosition()->equals($this->flagBlue)) {
                    $this->scores["red"]++;
                    $this->broadcastMessage(TF::YELLOW . "{$player->getName()} from team Red has captured the Blue flag!");
                } elseif ($this->getPlayerTeam($player) === "blue" && $block->getPosition()->equals($this->flagRed)) {
                    $this->scores["blue"]++;
                    $this->broadcastMessage(TF::YELLOW . "{$player->getName()} from team Blue has captured the Red flag!");
                } else {
                    $player->sendMessage(TF::RED . "You cannot capture your own team's flag!");
                    $event->cancel();
                }
            }
        }
    }

    private function isFlagBlock(Block $block): bool {
        return $block->getPosition()->equals($this->flagRed) || $block->getPosition()->equals($this->flagBlue);
    }

    private function checkStartConditions(): void {
        if (count($this->players) >= 2 && !$this->gameStarted) {
            $this->startGame();
        }
    }

    private function startGame(): void {
        $this->gameStarted = true;
        $this->broadcastMessage(TF::GREEN . "The game has started!");

        // Schedule the game to end after the game duration
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function (): void {
            $this->endGame();
        }), 20 * $this->gameDuration);
    }

    private function endGame(): void {
        $this->gameStarted = false;
        $winningTeam = $this->scores["red"] > $this->scores["blue"] ? "Red" : "Blue";
        $this->broadcastMessage(TF::GOLD . "The game has ended! Team {$winningTeam} wins!");

        // Reset game
        $this->players = [];
        $this->teams = ["red" => [], "blue" => []];
        $this->scores = ["red" => 0, "blue" => 0];
    }

    private function assignTeam(Player $player): void {
        $this->players[$player->getName()] = $player;

        // Assign to the team with fewer players
        if (count($this->teams["red"]) <= count($this->teams["blue"])) {
            $this->teams["red"][] = $player;
        } else {
            $player->teleport($this->blueSpawn);
            $this->teams["blue"][] = $player;
        }
    }

    private function removeFromTeam(Player $player): void {
        foreach (["red", "blue"] as $team) {
            if (($key = array_search($player, $this->teams[$team])) !== false) {
                unset($this->teams[$team][$key]);
            }
        }
    }

    private function getPlayerTeam(Player $player): string {
        foreach ($this->teams as $team => $members) {
            if (in_array($player, $members, true)) {
                return $team;
            }
        }
        return "unknown";
    }

    private function broadcastMessage(string $message): void {
        foreach ($this->players as $player) {
            $player->sendMessage($message);
        }
    }
}
