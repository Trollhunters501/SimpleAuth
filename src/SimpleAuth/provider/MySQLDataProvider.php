<?php

/*
 * SimpleAuth plugin for PocketMine-MP
 * Copyright (C) 2014 PocketMine Team <https://github.com/PocketMine/SimpleAuth>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
*/

declare(strict_types=1);

namespace SimpleAuth\provider;

use pocketmine\IPlayer;
use pocketmine\Player;
use pocketmine\OfflinePlayer;
use SimpleAuth\SimpleAuth;
use SimpleAuth\task\MySQLPingTask;

class MySQLDataProvider implements DataProvider{

	/** @var SimpleAuth */
	protected $plugin;

	/** @var \mysqli */
	protected $database;

	/** @var  @var bool */
	private $linkingready;


	public function __construct(SimpleAuth $plugin){
		$this->plugin = $plugin;
		$config = $this->plugin->getConfig()->get("dataProviderSettings");

		if(!isset($config["host"]) or !isset($config["user"]) or !isset($config["password"]) or !isset($config["database"])){
			$this->plugin->getLogger()->critical("Invalid MySQL settings");
			$this->plugin->setDataProvider(new DummyDataProvider($this->plugin));
			return;
		}

		$this->database = new \mysqli($config["host"], $config["user"], $config["password"], $config["database"], isset($config["port"]) ? $config["port"] : 3306);
		if($this->database->connect_error){
			$this->plugin->getLogger()->critical("Couldn't connect to MySQL: " . $this->database->connect_error);
			$this->plugin->setDataProvider(new DummyDataProvider($this->plugin));
			return;
		}

		$resource = $this->plugin->getResource("mysql.sql");
		$this->database->query(stream_get_contents($resource));
		fclose($resource);

		$this->linkingready = $this->database->query("SELECT * FROM information_schema.COLUMNS WHERE COLUMN_NAME = 'linkedign'")->fetch_assoc() !== null ? true : false;
		if(!$this->linkingready){
			$this->plugin->getLogger()->info("Update MySQL Schema to enable /link");
		}
		$this->plugin->getScheduler()->scheduleRepeatingTask(new MySQLPingTask($this->plugin, $this->database), 600); //Each 30 seconds
		$this->plugin->getLogger()->info("Connected to MySQL server");
	}

	// For backward compatibility with other plugins
	public function getPlayer(IPlayer $player){
		return $this->getPlayerData($player->getName());
	}

	public function getPlayerData(string $name){
		$name = trim(strtolower($name));

		$result = $this->database->query("SELECT * FROM simpleauth_players WHERE name = '" . $this->database->escape_string($name) . "'");

		if($result instanceof \mysqli_result){
			$data = $result->fetch_assoc();
			$result->free();
			if(isset($data["name"]) and strtolower($data["name"]) === $name){
				unset($data["name"]);
				return $data;
			}
		}

		return null;
	}

	public function isPlayerRegistered(IPlayer $player){
		return $this->getPlayerData($player->getName()) !== null;
	}

	public function unregisterPlayer(IPlayer $player){
		$name = trim(strtolower($player->getName()));
		$this->database->query("DELETE FROM simpleauth_players WHERE name = '" . $this->database->escape_string($name) . "'");
	}

	public function registerPlayer(IPlayer $player, $hash){
		$name = trim(strtolower($player->getName()));
		$data = [
			"registerdate" => time(),
			"logindate" => time(),
			"hash" => $hash,
		];


		$this->database->query("INSERT INTO simpleauth_players
			(name, registerdate, logindate, hash)
			VALUES
			('" . $this->database->escape_string($name) . "', " . intval($data["registerdate"]) . ", " . intval($data["logindate"]) . ", '" . $hash . "')");
		return $data;
	}

	public function savePlayer(string $name, array $config){
		$name = trim(strtolower($name));
		$this->database->query("UPDATE simpleauth_players SET ip = '" . $this->database->escape_string($config["ip"]) . "', registerdate = " . (int) $config["registerdate"] . ", logindate = " . (int) $config["logindate"] . ", lastip = '" . $this->database->escape_string($config["lastip"]) . "', hash = '" . $this->database->escape_string($config["hash"]) . "', linkedign = '" . $this->database->escape_string($config["linkedign"]) . "' WHERE name = '" . $this->database->escape_string($name) . "'");
	}

	public function updatePlayer(IPlayer $player, string $lastIp = null, string $ip = null, int $loginDate = null, string $linkedign = null) : bool{
		$name = trim(strtolower($player->getName()));
		if($lastIp !== null){
			$this->database->query("UPDATE simpleauth_players SET lastip = '" . $this->database->escape_string($lastIp) . "' WHERE name = '" . $this->database->escape_string($name) . "'");
		}
		if($loginDate !== null){
			$this->database->query("UPDATE simpleauth_players SET logindate = " . intval($loginDate) . " WHERE name = '" . $this->database->escape_string($name) . "'");
		}
		if($ip !== null){
			$this->database->query("UPDATE simpleauth_players SET ip = '" . $this->database->escape_string($ip) . "' WHERE name = '" . $this->database->escape_string($name) . "'");
		}
		if($linkedign !== null){
			$this->database->query("UPDATE simpleauth_players SET linkedign ='" . $this->database->escape_string($linkedign) . "' WHERE name = '" . $this->database->escape_string($name) . "'");
		}
		return true;
	}

	public function getLinked(string $name){
		if($this->database->query("SELECT * FROM information_schema.COLUMNS WHERE COLUMN_NAME = 'linkedign'")->fetch_assoc() == null){
			return null;
		}
		$name = trim(strtolower($name));
		$linked = $this->database->query("SELECT linkedign FROM simpleauth_players WHERE name = '" . $this->database->escape_string($name) . "'")->fetch_assoc();
		return $linked["linkedign"] ?? null;
	}

	public function linkXBL(Player $sender, OfflinePlayer $oldPlayer, string $oldIGN){
		$success = $this->updatePlayer($sender, null, null, null, $oldIGN);
		$success = $success && $this->updatePlayer($oldPlayer, null, null, null, $sender->getName());
		return $success;
	}

	public function unlinkXBL(string $playerign){
		$xblIGN = $this->getLinked($playerign);
		$xbldata = $this->getPlayerData($xblIGN);
		if(isset($xbldata)){
			$xbldata["linkedign"] = "";
			$this->savePlayer($xblIGN, $xbldata);
		}
		$pmdata = $this->getPlayerData($playerign);
		if(isset($pmdata)){
			$pmdata["linkedign"] = "";
			$this->savePlayer($playerign, $pmdata);
		}
		return $xblIGN;
	}

	public function isDBLinkingReady() : bool{
		return $this->linkingready;
	}

	public function close(){
		$this->database->close();
	}
}
