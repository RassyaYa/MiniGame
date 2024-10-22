<?php

namespace MiniGame;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\world\position\Position;
use pocketmine\utils\TextFormat as TF;
use pocketmine\scheduler\ClosureTask;
use pocketmine\block\VanillaBlocks;
use pocketmine\world\WorldManager;

class Main extends PluginBase implements Listener {
    private array $teams = [
        "red" => [],
        "blue" => [],
    ];
    private array $players = [];
    private array $spawnProtection = [];
    private array $scores = [
        "red" => 0,
        "blue" => 0,
    ];
    private Position $redSpawn;
    private Position $blueSpawn;
    private bool $gameStarted = false;

    public function onEnable(): void {
        $this->getLogger()->info("MiniGame Plugin Enabled!");

        // Set spawn positions (replace with actual coordinates)
        $this->redSpawn = new Position(100, 64, 100, $this->getServer()->getWorldManager()->getDefaultWorld());
        $this->blueSpawn = new Position(200, 64, 200, $this->getServer()->getWorldManager()->getDefaultWorld());

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game.");
            return false;
        }

        switch ($command->getName()) {
            case "joinred":
                $this->assignTeam($sender, "red");
                $sender->sendMessage(TF::GREEN . "You have joined the Red team!");
                return true;

            case "joinblue":
                $this->assignTeam($sender, "blue");
                $sender->sendMessage(TF::GREEN . "You have joined the Blue team!");
                return true;

            case "startgame":
                if (!$this->gameStarted) {
                    $this->startGame();
                    $sender->sendMessage(TF::GREEN . "Game has been started!");
                } else {
                    $sender->sendMessage(TF::RED . "A game is already in progress.");
                }
                return true;

            case "endgame":
                if ($this->gameStarted) {
                    $this->endGame();
                    $sender->sendMessage(TF::GREEN . "Game has been ended!");
                } else {
                    $sender->sendMessage(TF::RED . "No game is currently running.");
                }
                return true;

            default:
                return false;
        }
    }

    private function assignTeam(Player $player, ?string $team = null): void {
        $this->players[$player->getName()] = $player;

        if ($team === "red") {
            $this->teams["red"][] = $player;
            $player->teleport($this->redSpawn);
        } elseif ($team === "blue") {
            $this->teams["blue"][] = $player;
            $player->teleport($this->blueSpawn);
        } else {
            // Auto-assign to balance teams
            if (count($this->teams["red"]) <= count($this->teams["blue"])) {
                $this->teams["red"][] = $player;
                $player->teleport($this->redSpawn);
            } else {
                $this->teams["blue"][] = $player;
                $player->teleport($this->blueSpawn);
            }
        }

        $this->updateScoreboard();
    }

    private function startGame(): void {
        $this->gameStarted = true;
        // Reset scores
        $this->scores["red"] = 0;
        $this->scores["blue"] = 0;
        $this->updateScoreboard();
        // Spawn power-ups (example positions)
        $this->spawnPowerUp(new Position(150, 64, 150, $this->getServer()->getWorldManager()->getDefaultWorld()));
    }

    private function endGame(): void {
        $this->gameStarted = false;
        // Reset teams
        foreach ($this->teams as $team => $members) {
            foreach ($members as $member) {
                unset($this->teams[$team][$member->getName()]);
            }
        }
        $this->resetScoreboard();
    }

    private function updateScoreboard(): void {
        // Scoreboard logic would go here
        foreach ($this->players as $player) {
            $player->sendMessage(TF::GREEN . "Score: Red - " . $this->scores["red"] . ", Blue - " . $this->scores["blue"]);
        }
    }

    private function resetScoreboard(): void {
        foreach ($this->players as $player) {
            $player->sendMessage(TF::YELLOW . "Game has ended. Scores: Red - " . $this->scores["red"] . ", Blue - " . $this->scores["blue"]);
        }
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $victim = $event->getPlayer();
        $this->scores[$this->getPlayerTeam($victim)]++;
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($victim): void {
            $victim->teleport($this->getPlayerTeam($victim) === "red" ? $this->redSpawn : $this->blueSpawn);
            $victim->setHealth($victim->getMaxHealth());
            $victim->sendMessage(TF::GREEN . "You have respawned!");
        }), 20 * 10); // 10 seconds cooldown
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            $victim = $event->getEntity();

            if ($victim instanceof Player && isset($this->spawnProtection[$victim->getName()]) && $this->spawnProtection[$victim->getName()]) {
                $damager->sendMessage(TF::RED . "This player has spawn protection!");
                $event->cancel();
            }
        }
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $block = $event->getBlock();
        if ($block->getTypeId() === VanillaBlocks::DIAMOND_BLOCK()->getTypeId()) {
            $player = $event->getPlayer();
            $player->addEffect(new EffectInstance(Effect::getEffect(Effect::SPEED), 600, 1)); // Speed power-up
            $player->sendMessage(TF::GREEN . "You have picked up a speed power-up!");
        }
    }

    private function spawnPowerUp(Position $position): void {
        $powerUp = VanillaBlocks::DIAMOND_BLOCK(); // Example power-up block
        $this->getServer()->getWorldManager()->getDefaultWorld()->setBlock($position, $powerUp);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($position): void {
            $this->getServer()->getWorldManager()->getDefaultWorld()->setBlock($position, VanillaBlocks::AIR()); // Remove power-up after 30 seconds
        }), 20 * 30); // 30 seconds
    }

    private function getPlayerTeam(Player $player): string {
        foreach ($this->teams as $team => $members) {
            if (in_array($player, $members, true)) {
                return $team;
            }
        }
        return "unknown";
    }
}
