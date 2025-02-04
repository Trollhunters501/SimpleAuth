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

namespace SimpleAuth;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\IPlayer;
use pocketmine\utils\Config;
use pocketmine\permission\PermissionAttachment;
use pocketmine\permission\Permission;
use pocketmine\Player;
use pocketmine\OfflinePlayer;
use pocketmine\Server;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use SimpleAuth\event\PlayerAuthenticateEvent;
use SimpleAuth\event\PlayerDeauthenticateEvent;
use SimpleAuth\event\PlayerRegisterEvent;
use SimpleAuth\event\PlayerUnregisterEvent;
use SimpleAuth\provider\DataProvider;
use SimpleAuth\provider\DummyDataProvider;
use SimpleAuth\provider\MySQLDataProvider;
use SimpleAuth\provider\SQLite3DataProvider;
use SimpleAuth\task\ShowMessageTask;

class SimpleAuth extends PluginBase{

	/** @var PermissionAttachment[] */
	protected $needAuth = [];

	/** @var EventListener */
	protected $listener;

	/** @var DataProvider */
	protected $provider;

    /** @var int */
    protected $blockPlayers = 6;

    /** @var array */
	protected $blockSessions = [];

	/** @var string[] */
	protected $messages = [];
	protected $messageTask = null;
	public $notRelogged = [];
	private $allowLinking = false;

	/** @var string[] */
	public $devices;

	/**
	 * @api
	 *
	 * @param Player $player
	 *
	 * @return bool
	 */
	public function isPlayerAuthenticated(Player $player){
		return !isset($this->needAuth[spl_object_hash($player)]);
	}

	/**
	 * @api
	 *
	 * @param IPlayer $player
	 *
	 * @return bool
	 */
	public function isPlayerRegistered(IPlayer $player){
		return $this->provider->isPlayerRegistered($player);
	}

	/**
	 * @api
	 *
	 * @param Player $player
	 *
	 * @return bool True if call not blocked
	 */
	public function authenticatePlayer(Player $player){
		if($this->isPlayerAuthenticated($player)){
			return true;
		}

		$this->getServer()->getPluginManager()->callEvent($ev = new PlayerAuthenticateEvent($this, $player));
		if($ev->isCancelled()){
			return false;
		}

		if(isset($this->needAuth[spl_object_hash($player)])){
			$attachment = $this->needAuth[spl_object_hash($player)];
			$player->removeAttachment($attachment);
			unset($this->needAuth[spl_object_hash($player)]);
		}

		$player->recalculatePermissions();
		$player->sendMessage(TextFormat::GREEN . ($this->getMessage("login.success") ?? "You have been authenticated"));

		$this->getMessageTask()->removePlayer($player);

		unset($this->blockSessions[$player->getAddress() . ":" . strtolower($player->getName())]);
		$this->provider->updatePlayer($player, hash('md5', $player->getAddress() . ($this->devices[$player->getName()] ?? '')), $player->getAddress(), time(), null);
		unset($this->devices[$player->getName()]);
		return true;
	}

	/**
	 * @api
	 *
	 * @param Player $player
	 *
	 * @return bool True if call not blocked
	 */
	public function deauthenticatePlayer(Player $player){
		if(!$this->isPlayerAuthenticated($player)){
			return true;
		}

		$this->getServer()->getPluginManager()->callEvent($ev = new PlayerDeauthenticateEvent($this, $player));
		if($ev->isCancelled()){
			return false;
		}

		$attachment = $player->addAttachment($this);
		$this->removePermissions($attachment);
		$this->needAuth[spl_object_hash($player)] = $attachment;

		$this->sendAuthenticateMessage($player);

		$this->getMessageTask()->addPlayer($player);

		return true;
	}

	public function tryAuthenticatePlayer(Player $player){
		if($this->blockPlayers <= 0 and $this->isPlayerAuthenticated($player)){
			return;
		}

		if(count($this->blockSessions) > 2048){
			$this->blockSessions = [];
		}

		if(!isset($this->blockSessions[$player->getAddress()])){
			$this->blockSessions[$player->getAddress() . ":" . strtolower($player->getName())] = 1;
		}else{
			$this->blockSessions[$player->getAddress() . ":" . strtolower($player->getName())]++;
		}

		if($this->blockSessions[$player->getAddress() . ":" . strtolower($player->getName())] > $this->blockPlayers){
			$player->kick($this->getMessage("login.error.block") ?? "Too many tries!", true);
			$this->getServer()->getNetwork()->blockAddress($player->getAddress(), 600);
		}
	}

	/**
	 * @api
	 *
	 * @param IPlayer $player
	 * @param string  $password
	 *
	 * @return bool
	 */
	public function registerPlayer(IPlayer $player, $password){
		if(!$this->isPlayerRegistered($player)){
			$this->getServer()->getPluginManager()->callEvent($ev = new PlayerRegisterEvent($this, $player));
			if($ev->isCancelled()){
				return false;
			}
			$this->provider->registerPlayer($player, $this->hash(strtolower($player->getName()), $password));
			return true;
		}
		return false;
	}

	/**
	 * @api
	 *
	 * @param IPlayer $player
	 *
	 * @return bool
	 */
	public function unregisterPlayer(IPlayer $player){
		if($this->isPlayerRegistered($player)){
			$this->getServer()->getPluginManager()->callEvent($ev = new PlayerUnregisterEvent($this, $player));
			if($ev->isCancelled()){
				return false;
			}
			$this->provider->unregisterPlayer($player);
		}

		return true;
	}

	/**
	 * @api
	 *
	 * @param DataProvider $provider
	 */
	public function setDataProvider(DataProvider $provider){
		$this->provider = $provider;
	}

	/**
	 * @api
	 *
	 * @return DataProvider
	 */
    public function getDataProvider() : DataProvider{
        return $this->provider;
    }

	/* -------------------------- Non-API part -------------------------- */

	public function closePlayer(Player $player){
		unset($this->needAuth[spl_object_hash($player)]);
		$this->getMessageTask()->removePlayer($player);
	}

    protected function checkPassword($pl, $password) : bool{
        $data = $this->getDataProvider()->getPlayerData($pl->getName());
        if($data === null){
            return false;
        }
        $passok = hash_equals($data["hash"], $this->hash(strtolower($pl->getName()), $password));
        return $passok;
    }

	public function sendAuthenticateMessage(Player $player){
		$config = $this->provider->getPlayerData($player->getName());
		$player->sendMessage(TextFormat::ITALIC . TextFormat::GRAY . $this->getMessage("join.message1") ?? "This server requires account registration.");
		$player->sendMessage(TextFormat::ITALIC . TextFormat::GRAY . $this->getMessage("join.message2") ?? "You must login to play.");
		if($config === null){
			$player->sendMessage(TextFormat::YELLOW . $this->getMessage("join.register") ?? "Please register using: /register <password>");
		}else{
			if($this->allowLinking) $player->sendMessage(TextFormat::YELLOW . ($this->getMessage("join.loggingas") ? $this->getMessage("join.loggingas") . $player->getName() : "You are connecting as " . $player->getName()));
			$player->sendMessage(TextFormat::YELLOW . $this->getMessage("join.login") ?? "Log in using: /login <password>");
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		switch($command->getName()){
			case "login":
				if($sender instanceof Player){

					if($this->isPlayerAuthenticated($sender)){
						return true;
					}

					if(!$this->isPlayerRegistered($sender) or ($this->provider->getPlayerData($sender->getName())) === null){
						$sender->sendMessage(TextFormat::RED . $this->getMessage("login.error.registered") ?? "This account is not registered.");
						return true;
					}

					$password = $args[0];

					$data = $this->provider->getPlayerData($sender->getName());

					if(hash_equals($data["hash"], $this->hash(strtolower($sender->getName()), $password)) and $this->authenticatePlayer($sender)){
						// LOGIN SUCCESS!!
						return true;
					}else{
						$this->tryAuthenticatePlayer($sender);
						$sender->sendMessage(TextFormat::RED . $this->getMessage("login.error.password") ?? "Incorrect Password");
						return true;
					}
				}
				break;
			case "register":
				if($sender instanceof Player){
					if($this->isPlayerRegistered($sender)){
						$sender->sendMessage(TextFormat::RED . $this->getMessage("register.error.registered") ?? "This account is already registered.");
						return true;
					}

					$password = implode(" ", $args);
					if(strlen($password) < $this->getConfig()->get("minPasswordLength")){
						$sender->sendMessage($this->getMessage("register.error.password") ?? "Your password is too short!");
						return true;
					}

					if($this->registerPlayer($sender, $password) and $this->authenticatePlayer($sender)){
						return true;
					}else{
						$sender->sendMessage(TextFormat::RED . $this->getMessage("register.error.general") ?? "Error during authentication.");
						return true;
					}
				}else{
					$sender->sendMessage(TextFormat::RED . "This command only works in-game.");
					return true;
				}
				break;

			case "link":
				if(!($sender instanceof Player) or count($args) !== 2) return false;
				if(!$this->getConfig()->get("allowLinking")){
					$sender->sendMessage(TextFormat::AQUA . "Please add 'allowLinking: true' to SimpleAuth config.yml");
					return true;
				}
				if(!$this->getDataProvider()->isDBLinkingReady()){
					$sender->sendMessage(TextFormat::AQUA . "Please update your SimpleAuth DataBase for linking: see config.yml");
					return true;
				}
				$oldIGN = $args[0];
				$oldPWD = $args[1];
				$linked = $this->getDataProvider()->getLinked($sender->getName());
				if($linked !== null and $linked !== "" and strtolower($sender->getName()) === strtolower($linked)){
					$sender->sendMessage(TextFormat::RED . ($this->getMessage("link.sameign") ?? "You cannot link to the same account you are using"));
					return true;
				}
				if(($linked !== null and $linked !== "") or isset($this->notRelogged[spl_object_hash($sender)])){
					$sender->sendMessage(TextFormat::RED . ($this->getMessage("link.selfalreadylinked") ?? "You must unlink this account and relog before you link again"));
					return true;
				}
				$oldLinked = $this->getDataProvider()->getLinked($oldIGN);
				if($oldLinked !== null and $oldLinked !== ""){
					$sender->sendMessage(TextFormat::RED . ($this->getMessage("link.otheralreadylinked") ?? "That account is already linked to ") . $oldLinked);
					return true;
				}
				$oldPlayer = Server::getInstance()->getOfflinePlayer($oldIGN);

				if($oldPlayer instanceof OfflinePlayer && $this->checkPassword($oldPlayer, $oldPWD)){

					$success = $this->getDataProvider()->linkXBL($sender, $oldPlayer, $oldIGN);

					if($success){
						$this->notRelogged[spl_object_hash($sender)] = true;
						$line1 = $this->getMessage("link.success1") ?? "Accounts Linked! Login again with the password for ";
						$line2 = $this->getMessage("link.success2") ?? "Use /unlink to unlink these accounts at any time";
						$message = TextFormat::GREEN . $line1 . $oldIGN . "\n" . TextFormat::RED . $line2;
						$sender->sendMessage($message);
						return true;
					}
				}
				$sender->sendMessage(TextFormat::RED . ($this->getMessage("link.error") ?? "There was a problem linking the accounts"));
				return false;
				break;

			case "unlink":
				if(!$this->getConfig()->get("allowLinking")){
					$sender->sendMessage(TextFormat::AQUA . "Please enable 'allowLinking' in SimpleAuth config.yml");
					return true;
				}
                if(!($sender instanceof Player)){
				    if (!isset($args[0])) {
                        $sender->sendMessage(TextFormat::RED . ($this->getMessage("link.consoleerror") ?? "Please use /unlink <player> with player IGN in double quotes if it contains spaces"));
                        return true;
                    }
                    $linked = $this->getDataProvider()->getLinked($args[0]);
                    if($linked === null or $linked === ""){
                        $sender->sendMessage(TextFormat::RED . ($this->getMessage("link.consoleunlinkerror") ?? "That account is not linked"));
                        return true;
                    }
                    $xboxIGN = $this->getDataProvider()->unlinkXBL($args[0]);
                    if($xboxIGN !== null && $xboxIGN !== ""){
                        $line1 = $this->getMessage("link.consoleunlinked") ? $this->getMessage("link.consoleunlinked")
                            . $args[0] : "You have unlinked the account " . $args[0];
                        $message = TextFormat::GREEN . $line1;
                        $sender->sendMessage($message);
                    }else{
                        $sender->sendMessage(TextFormat::RED . $this->getMessage("link.unlinkerror") ?? "There was a problem unlinking those accounts");
                    }
                    return true;
                }
				$linked = $this->getDataProvider()->getLinked($sender->getName());
				if($linked === null or $linked === ""){
					$sender->sendMessage(TextFormat::RED . ($this->getMessage("link.notlinkederror") ?? "Your account is not linked"));
					return true;
				}
				$xboxIGN = $this->getDataProvider()->unlinkXBL($sender->getName());
				if($xboxIGN !== null && $xboxIGN !== ""){
					$currentIGN = $sender->getName();
					if(isset($this->notRelogged[spl_object_hash($sender)])){
						$currentIGN = $xboxIGN;
						$xboxIGN = $sender->getName();
					}
					$line1 = $this->getMessage("link.unlink1") ? $this->getMessage("link.unlink1")
						. $currentIGN : "Account " . $currentIGN . " unlinked!";
					$line2 = $this->getMessage("link.unlink2") ? $this->getMessage("link.unlink2")
						. $xboxIGN : "Login from now on with your regular password for $xboxIGN";
					$message = TextFormat::GREEN . $line1 . "\n" . TextFormat::RED . $line2;
					$this->notRelogged[spl_object_hash($sender)] = true;
					$sender->sendMessage($message);
				}else{
					$sender->sendMessage(TextFormat::RED . $this->getMessage("link.unlinkerror") ?? "There was a problem unlinking your accounts");
					return true;
				}
				return true;
		}
		return false;
	}

    /**
     * @param array $messages
     * @return array
     */
    private function parseMessages(array $messages) : array{
        $result = [];
        foreach($messages as $key => $value){
            if(is_array($value)){
                foreach($this->parseMessages($value) as $k => $v){
                    $result[$key . "." . $k] = $v;
                }
            }else{
                $result[$key] = $value;
            }
        }

        return $result;
    }


    /**
     * @param $key
     * @return null|string
     */
    public function getMessage($key){
		return isset($this->messages[$key]) ? $this->messages[$key] : null;
	}

	public function onEnable(){
		$this->saveDefaultConfig();
		$this->reloadConfig();

		$this->saveResource("messages.yml", false);

		$messages = (new Config($this->getDataFolder() . "messages.yml"))->getAll();

		$this->messages = $this->parseMessages($messages);

		$registerCommand = $this->getCommand("register");
		$registerCommand->setUsage($this->getMessage("register.usage") ?? "/register <password>");
		$registerCommand->setDescription($this->getMessage("register.description") ?? "Registers an account");
		$registerCommand->setPermissionMessage($this->getMessage("register.permission") ?? "You do not have permission to use the register command!");

		$loginCommand = $this->getCommand("login");
		$loginCommand->setUsage($this->getMessage("login.usage") ?? "/login <password>");
		$loginCommand->setDescription($this->getMessage("login.description") ?? "Logs into an account");
		$loginCommand->setPermissionMessage($this->getMessage("login.permission") ?? "You do not have permission to use the login command!");

		$this->blockPlayers = (int) $this->getConfig()->get("blockAfterFail", 6);
		$this->allowLinking = $this->getConfig()->get("allowLinking");
		$provider = $this->getConfig()->get("dataProvider");
		unset($this->provider);
		switch(strtolower($provider)){
			case "sqlite3":
				$this->getLogger()->debug("Using SQLite3 data provider");
				$provider = new SQLite3DataProvider($this);
				break;
			case "mysql":
				$this->getLogger()->debug("Using MySQL data provider");
				$provider = new MySQLDataProvider($this);
				break;
			case "none":
			default:
				$provider = new DummyDataProvider($this);
				break;
		}

		if(!isset($this->provider) or !($this->provider instanceof DataProvider)){ //Fix for getting a Dummy provider
			$this->provider = $provider;
		}

		$this->listener = new EventListener($this);
		$this->getServer()->getPluginManager()->registerEvents($this->listener, $this);

		foreach($this->getServer()->getOnlinePlayers() as $player){
			$this->deauthenticatePlayer($player);
		}

		$this->getLogger()->info("Everything loaded!");
	}

	public function onDisable(){
		$this->getServer()->getPluginManager();
		$this->provider->close();
		$this->messageTask = null;
		$this->blockSessions = [];
	}

    /**
     * @param $perm1
     * @param $perm2
     * @return int
     */
    public static function orderPermissionsCallback($perm1, $perm2) : int{
		if(self::isChild($perm1, $perm2)){
			return -1;
		}elseif(self::isChild($perm2, $perm1)){
			return 1;
		}else{
			return 0;
		}
	}

    /**
     * @param $perm
     * @param $name
     * @return bool
     */
    public static function isChild($perm, $name) : bool{
		$perm = explode(".", $perm);
		$name = explode(".", $name);

		foreach($perm as $k => $component){
			if(!isset($name[$k])){
				return false;
			}elseif($name[$k] !== $component){
				return false;
			}
		}

		return true;
	}

    /**
     * @param PermissionAttachment $attachment
     */
    protected function removePermissions(PermissionAttachment $attachment){
		$permissions = [];
		foreach($this->getServer()->getPluginManager()->getPermissions() as $permission){
			$permissions[$permission->getName()] = false;
		}

		$permissions["pocketmine.command.help"] = true;
		$permissions[Server::BROADCAST_CHANNEL_USERS] = true;
		$permissions[Server::BROADCAST_CHANNEL_ADMINISTRATIVE] = false;

		unset($permissions["simpleauth.chat"]);
		unset($permissions["simpleauth.move"]);
		unset($permissions["simpleauth.lastid"]);

		//Do this because of permission manager plugins
		if($this->getConfig()->get("disableRegister") == true){
			$permissions["simpleauth.command.register"] = false;
		}else{
			$permissions["simpleauth.command.register"] = true;
		}

		if($this->getConfig()->get("disableLogin") == true){
			$permissions["simpleauth.command.login"] = false;
		}else{
			$permissions["simpleauth.command.login"] = true;
		}
		uksort($permissions, [SimpleAuth::class, "orderPermissionsCallback"]); //Set them in the correct order

		$attachment->setPermissions($permissions);
	}

	/**
	 * Uses SHA-512 [http://en.wikipedia.org/wiki/SHA-2] and Whirlpool [http://en.wikipedia.org/wiki/Whirlpool_(cryptography)]
	 *
	 * Both of them have an output of 512 bits. Even if one of them is broken in the future, you have to break both of them
	 * at the same time due to being hashed separately and then XORed to mix their results equally.
	 *
	 * @param string $salt
	 * @param string $password
	 *
	 * @return string[128] hex 512-bit hash
	 */
	private function hash(string $salt, string $password) : string{
		return bin2hex(hash("sha512", $password . $salt, true) ^ hash("whirlpool", $salt . $password, true));
	}

	/**
	 * @return ShowMessageTask
	 */
	protected function getMessageTask() : ShowMessageTask{
		if($this->messageTask === null){
			$this->messageTask = new ShowMessageTask($this);
			$this->getScheduler()->scheduleRepeatingTask($this->messageTask, 10);
		}

		return $this->messageTask;
	}
}
