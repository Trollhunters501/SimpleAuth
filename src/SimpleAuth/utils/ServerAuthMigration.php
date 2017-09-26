<?php

namespace SimpleAuth\utils;

use pocketmine\OfflinePlayer;
use pocketmine\utils\Config;
use SimpleAuth\SimpleAuth;
use pocketmine\utils\TextFormat;

class ServerAuthMigration{

    private $plugin;

    const PREFIX = TextFormat::AQUA . "[ServerAuth -> SimpleAuth Migrator] " . TextFormat::WHITE;

    public function __construct(SimpleAuth $plugin){
        $this->plugin = $plugin;
    }

    public function migrateFromYML(string $algorithm, bool $saveprog = true): string{
        if(!in_array($algorithm, hash_algos())) return self::PREFIX . TextFormat::RED . "Error: Unknown hash algorithm!";
        $dir = $this->plugin->getServer()->getPluginPath() . "ServerAuth" . DIRECTORY_SEPARATOR . "users" . DIRECTORY_SEPARATOR;
        if(!is_dir($dir)) return self::PREFIX . TextFormat::RED . "Error: Directory not found!";
        $files = array_diff(scandir($dir), ['..', '.']);
        $this->plugin->getServer()->getLogger()->info(self::PREFIX . "Migrating: ");
        $start = microtime(true);
        foreach($files as $f){
            $data = yaml_parse_file($dir . $f);
            $name = rtrim($f, ".yml");
            $player = new OfflinePlayer($this->plugin->getServer(), $name);
            $this->plugin->getDataProvider()->registerPlayer($player, "!migrate_" . $algorithm . "!" . $data["password"]);
            $this->plugin->getDataProvider()->updatePlayer($player, $lastIP = 0, $ip = 0, $loginDate = 0, $skinhash = 0);
            echo $name . "\n";
        }
        if($saveprog){
            $this->plugin->getServer()->getLogger()->info(self::PREFIX . "Saving migration progress data...");
            $conf = new Config($this->plugin->getDataFolder() . "progress.yml");
            $conf->set("all", count($files));
            $conf->set("already", 0);
            $conf->save();
        }
        $end = microtime(true);
        return self::PREFIX . TextFormat::GREEN . "Migrated " . count($files) . " players in " . round(($end - $start) * 1000) . " ms.";
    }

}