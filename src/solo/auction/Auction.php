<?php

declare(strict_types=1);

namespace solo\auction;

use muqsit\invmenu\InvMenu;
use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Event;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\Server;
use pocketmine\utils\Config;
use function array_shift;
use function implode;
use function intval;
use function is_numeric;
use function strpos;
use function time;
use function trim;

class Auction extends PluginBase{

	public static $prefix = "§b<§f시스템§d> §f";

	protected function onEnable() : void{
		FA::$plugin = $this;
		FA::$server = $this->getServer();
		FA::$scheduler = $this->getScheduler();
		FA::$config = $this->getConfig();
		FA::$economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");

		FA::registerCommand("경매 시작", "손에 들고 있는 아이템으로 경매를 시작합니다.", "/경매 시작 (최소가) (갯수)", "all", function(Command $self, CommandSender $sender, array $args){
			if(!$sender instanceof Player){
				return $self->message("인게임에서만 사용가능합니다.");
			}
			$firstPrice = array_shift($args);
			if(!$self->number($firstPrice) or $firstPrice < 0){
				return $self->usage();
			}
			$firstPrice = intval($firstPrice);

			$count = array_shift($args);
			if(!$self->number($count) or $count < 1){
				return $self->usage();
			}
			$count = intval($count);

			$item = $sender->getInventory()->getItemInHand();
			if($item->isNull()){
				return $self->message("손에 아이템을 들고 명령을 실행해주세요.");
			}

			$item->setCount($count);

			try{
				FA::$auction->start($sender, $item, $firstPrice);
			}catch(AuctionException $e){
				$self->message($e->getMessage());
			}
		});

		FA::registerCommand("경매 입찰", "입력한 가격으로 입찰합니다.", "/경매 입찰 (가격)", "all", function(Command $self, CommandSender $sender, array $args){
			if(!$sender instanceof Player){
				return $self->message("인게임에서만 사용가능합니다.");
			}
			$price = array_shift($args);
			if(!$self->number($price) or $price < 0){
				return $self->usage();
			}
			$price = intval($price);

			try{
				FA::$auction->tryBidding($sender, $price);
			}catch(AuctionException $e){
				$self->message($e->getMessage());
			}
		});

		FA::registerCommand("경매 취소", "진행중이던 경매를 취소합니다.", "/경매 취소", "op", function(Command $self, CommandSender $sender, array $args){
			try{
				FA::$auction->cancel($sender);
			}catch(AuctionException $e){
				$self->message($e->getMessage());
			}
		});

		FA::registerCommand("경매 아이템수령", "경매에 낙찰되었으나 아이템을 수령하지 못한 경우 이 명령을 실행해주세요.", "/경매 아이템수령", "all", function(Command $self, CommandSender $sender, array $args){
			if(!$sender instanceof Player){
				return $self->message("인게임에서만 사용가능합니다.");
			}
			try{
				FA::$auction->fetchItem($sender);
			}catch(AuctionException $e){
				$self->message($e->getMessage());
			}
		});

		FA::registerCommand("경매 금지템등록", "경매에 올릴 수 있는 아이템을 제한합니다.", "/경매 금지템등록 (id:damage)", "op", function(Command $self, CommandSender $sender, array $args){
			$str = array_shift($args);
			if($str === null){
				return $self->usage();
			}

			try{
				$item = LegacyStringToItemParser::getInstance()->parse($str);
			}catch(\InvalidArgumentException $e){
				return $self->message($e->getMessage());
			}
			FA::$blacklist->addItem($item);
			$self->message($item->getName() . " 을(를) 추가하였습니다.");
		});

		FA::registerCommand("경매 금지단어등록", "경매에 올릴 수 있는 아이템의 이름을 제한합니다.", "/경매 금지단어등록 (단어...)", "op", function(Command $self, CommandSender $sender, array $args){
			$word = trim(implode(" ", $args));

			if(empty($word)){
				return $self->usage();
			}

			FA::$blacklist->addWord($word);
			$self->message($word . " 을(를) 추가하였습니다.");
		});

		FA::registerCommand("경매 아이템확인", "경매가 진행중인 아이템이 궁금한 경우 이 명령을 실행해주세요.", "/경매 아이템확인", "all", function(Command $self, CommandSender $sender, array $args){
			if(!$sender instanceof Player){
				return $self->message("인게임에서만 사용가능합니다.");
			}

			$item = FA::$auction->item;

			if($item->getId() === 0)
				return $self->message('현재 경매가 진행중이지 않습니다.');

			$pos = $sender->getPosition();
			$pos->y = $pos->y + 3;

			$menu = InvMenu::create(InvMenu::TYPE_CHEST);
			$menu->setName("현재 진행중인 경매 아이템");
			$menu->setListener(InvMenu::readonly());
			$menu->getInventory()->setItem(13, $item);
			$menu->send($sender);
		});

		FA::$auction = new AuctionProcess(new Config($this->getDataFolder() . "auction.yml", Config::YAML));
		FA::$blacklist = new AuctionBlacklist(new Config($this->getDataFolder() . "blacklist.yml", Config::YAML));
	}

	protected function onDisable() : void{
		FA::$auction->save();
		FA::$blacklist->save();
	}
}

class AuctionBlacklist{

	public $items;
	public $words;

	public function __construct(Config $provider){
		$this->provider = $provider;

		$this->items = $provider->get("items", []);
		$this->words = $provider->get("words", []);

		foreach($this->items as $key => $item){
			$this->items[$key] = Item::jsonDeserialize($item);
		}
	}

	public function addItem(Item $item){
		$this->items[] = $item;
	}

	public function addWord(string $word){
		$this->words[] = $word;
	}

	public function contains(Item $item){
		if(!FA::conf("auction.allowsDurable", true) and $item instanceof Durable){
			return true;
		}
		if(!FA::conf("auction.allowsUsed", false) and $item->getMeta() !== 0){
			return true;
		}
		foreach($this->items as $check){
			if($check->equals($item, true, false)){
				return true;
			}
		}
		foreach($this->words as $word){
			if(strpos($item->getName(), $word) !== false){
				return true;
			}
		}
		return false;
	}

	public function save(){
		$items = [];
		foreach($this->items as $item){
			$items[] = $item->jsonSerialize();
		}
		$this->provider->set("items", $items);
		$this->provider->set("words", $this->words);
		$this->provider->save();
	}
}

class AuctionException extends \Exception{

}

class AuctionProcess implements Listener{
	/** @var Config */
	public $provider;

	/** @var Player|string|null */
	public $issuer;

	/** @var Item */
	public $item;

	/** @var Player|string|null */
	public $potent;

	/** @var int */
	public $firstPrice;

	/** @var int */
	public $price;

	/** @var int */
	public $delayUntil = 0;

	/** @var array */
	public $storage = [];

	/** @var TaskHandler */
	public $biddingTask = null;

	public function __construct(Config $provider){
		$this->provider = $provider;
		$this->issuer = $provider->get("issuer", null);
		$this->item = Item::jsonDeserialize($provider->get("item", null) ?? ["id" => ItemIds::AIR]);
		$this->potent = $provider->get("potent", null);
		$this->firstPrice = $provider->get("firstPrice", null);
		$this->price = $provider->get("price", null);
		$this->delayUntil = $provider->get("delayUntil", 0);
		$this->storage = $provider->get("storage", []);

		FA::$server->getPluginManager()->registerEvents($this, FA::$plugin);

		if($this->issuer !== null){
			FA::broadcast("진행중이던 경매를 계속합니다.");
			$this->postponeBidding(FA::conf("auction.biddingTime", 15));
		}
	}

	/**
	 * @param Player $issuer
	 * @param Item   $item
	 * @param int    $firstPrice
	 *
	 * @throws AuctionException
	 */
	public function start(Player $issuer, Item $item, int $firstPrice){
		if($this->issuer !== null){
			throw new AuctionException("이미§f " . $this->issuer() . "님께서 경매를 진행중입니다.");
		}
		if($this->delayUntil > time()){
			throw new AuctionException("경매를§f " . ($this->delayUntil - time()) . "초 동안 할 수 없습니다.");
		}
		if(FA::$blacklist->contains($item)){
			throw new AuctionException("해당 아이템은 경매 금지 아이템으로 등록되어 있습니다.");
		}
		if(!$issuer->getInventory()->contains($item)){
			throw new AuctionException("아이템을 가지고 있지 않습니다.");
		}
		$this->issuer = $issuer;
		$this->firstPrice = $this->price = $firstPrice;
		$this->item = $item;
		$issuer->getInventory()->removeItem($item);
		FA::broadcast($this->issuer->getName() . "님이 §7 [ §f" . FA::name($item) . "§r§7 ] §f(을)를 경매로 올렸습니다! 최소 입찰 가격은 §f" . $firstPrice . "원 입니다.");
		FA::broadcast("경매중인 아이템의 상세 정보를 확인하려면 §f/경매 아이템확인 §7명령어를 입력해주세요!");

		$this->postponeBidding(FA::conf("auction.biddingTime", 15));
	}

	public function issuer(){
		return $this->issuer instanceof Player ? $this->issuer->getName() : $this->issuer;
	}

	public function potent(){
		return $this->potent instanceof Player ? $this->potent->getName() : $this->potent;
	}

	/**
	 * @param CommandSender $sender
	 *
	 * @throws AuctionException
	 */
	public function cancel(CommandSender $sender){
		if($this->issuer() === $sender->getName() or $sender->hasPermission(DefaultPermissions::ROOT_OPERATOR)){
			$this->refund();
			FA::broadcast($sender->getName() . "님이 경매를 취소하였습니다.");
			$this->giveItem($this->issuer, $this->item);
			$this->end();
		}else{
			throw new AuctionException("입찰을 취소할 권한이 없습니다.");
		}
	}

	/**
	 * @param Player $potent
	 * @param int    $price
	 *
	 * @throws AuctionException
	 */
	public function tryBidding(Player $potent, int $price){
		if($this->issuer === $potent){
			throw new AuctionException("본인의 아이템에 입찰할 수 없습니다.");
		}
		if($this->issuer === null){
			throw new AuctionException("경매가 진행되고 있지 않습니다.");
		}
		if($price <= $this->price){
			throw new AuctionException("입찰가는 §f" . $this->price . "원보다 높아야 합니다.");
		}
		if(FA::$economy->reduceMoney($potent, $price) !== 1){ // RET_SUCCESS
			throw new AuctionException("돈이 부족합니다.");
		}
		$this->refund();

		$this->potent = $potent;
		$this->price = $price;

		FA::broadcast($potent->getName() . "님이§f " . $this->price . "원에 입찰하였습니다.");
		FA::broadcast("현재 경매 진행중인 아이템의 상세 정보를 확인하려면 §f/경매 아이템확인 §7명령어를 입력해주세요!");
		$this->postponeBidding(15);
	}

	public function refund(){
		if($this->potent === null)
			return;

		FA::$economy->addMoney($this->potent, $this->price);
		FA::msg($this->potent, "입찰 금액을 환불받았습니다.");
		$this->potent = null;
		$this->price = null;
	}

	public function postponeBidding(int $seconds){
		if($this->biddingTask instanceof TaskHandler)
			$this->biddingTask->cancel();

		$self = $this;
		$this->biddingTask = FA::delayedTask(function() use ($self){
			$self->bidding();
		}, 20 * $seconds);
	}

	public function bidding(){
		if($this->potent === null){
			FA::broadcast("입찰자가 아무도 없어 경매가 취소되었습니다.");

			$this->giveItem($this->issuer, $this->item);
		}else{
			FA::broadcast($this->potent() . "님이 §f" . $this->price . "원으로 낙찰되었습니다!");

			$potent = FA::$server->getPlayerExact($this->potent()) ?? $this->potent();
			$this->giveItem($potent, $this->item);

			FA::$economy->addMoney($this->issuer, $this->price);
		}
		$this->end();
	}

	public function giveItem($player, Item $item){
		if($player instanceof Player and $player->isOnline()){
			if(!$player->getInventory()->canAddItem($item)){
				$this->storeItem($player, $item);
				FA::msg($player, "인벤토리가 꽉 차있어 아이템을 수령할 수 없습니다. 인벤토리를 비운 후, /경매 아이템수령 명령어로 아이템을 받아가세요.");
			}else{
				$player->getInventory()->addItem($item);
				FA::msg($player, FA::name($item) . "를 수령하였습니다.");
			}
		}else{
			$this->storeItem($player, $item);
		}
	}

	public function storeItem($player, Item $item){
		if($player instanceof Player)
			$player = $player->getName();

		if(!isset($this->storage[$player]))
			$this->storage[$player] = [];

		$this->storage[$player][] = $item->jsonSerialize();
	}

	public function fetchItem(Player $player){
		if(!isset($this->storage[$player->getName()]))
			throw new AuctionException("수령할 아이템이 없습니다.");

		$storage = $this->storage[$player->getName()];
		$error = null;
		foreach($storage as $key => $itemSerialized){
			$item = Item::jsonDeserialize($itemSerialized);

			if(!$player->getInventory()->canAddItem($item)){
				$error = new AuctionException("인벤토리에 공간이 없습니다.");
				break;
			}

			$player->getInventory()->addItem($item);
			FA::msg($player, FA::name($item) . "를 수령하였습니다.");
			unset($storage[$key]);
		}

		if(empty($storage)){
			unset($this->storage[$player->getName()]);
		}else{
			$this->storage[$player->getName()] = $storage;
		}

		if($error !== null)
			throw $error;
	}

	public function onPlayerJoin(PlayerJoinEvent $event){
		if(isset($this->storage[$event->getPlayer()->getName()])){
			FA::msg($event->getPlayer(), "경매 중에 수령하지 않은 아이템이 있습니다. /경매 아이템수령 명령어를 통해 수령해주세요.");
		}
	}

	public function end(){
		$this->issuer = null;
		$this->item = ItemFactory::air();
		$this->potent = null;
		$this->delayUntil = time() + FA::conf("auction.delay", 10);

		if($this->biddingTask instanceof TaskHandler){
			$this->biddingTask->cancel();
			$this->biddingTask = null;
		}
	}

	public function save(){
		$this->provider->setAll([
			"issuer" => $this->issuer(),
			"item" => $this->item->jsonSerialize(),
			"potent" => $this->potent(),
			"price" => $this->price,
			"firstPrice" => $this->firstPrice,
			"delayUntil" => $this->delayUntil,
			"storage" => $this->storage
		]);
		$this->provider->save();
	}
}

// fast access class
abstract class FA{
	/** @var Auction */
	public static $plugin;
	/** @var Server */
	public static $server;
	/** @var TaskScheduler */
	public static $scheduler;
	/** @var Config */
	public static $config;

	/** @var EconomyAPI */
	public static $economy;

	/** @var AuctionProcess */
	public static $auction;
	/** @var AuctionBlacklist */
	public static $blacklist;

	public static function delayedTask(callable $callback, int $ticks){
		return FA::$scheduler->scheduleDelayedTask(FA::task($callback), $ticks);
	}

	public static function repeatingTask(callable $callback, int $ticks, int $delay = 0){
		return FA::$scheduler->scheduleDelayedRepeatingTask(FA::task($callback), $delay, $ticks);
	}

	public static function task(callable $callback){
		return new class($callback) extends Task{
			public function __construct(callable $callback){
				$this->callback = $callback;
			}

			public function onRun() : void{
				$callback = $this->callback;
				$callback(FA::$server);
			}
		};
	}

	public static function callEvent(Event $event){
		$event->call();
	}

	public static function registerCommand(string $name, string $description, string $usage, string $permission, callable $callback){
		FA::$server->getCommandMap()->register("auction", new class($name, $description, $usage, $permission, $callback) extends Command{

			private $permission;
			private $callback;
			private $temporalSender;

			public function __construct(string $name, string $description, string $usage, string $permission, callable $callback){
				parent::__construct($name, $description, $usage);
				$this->permission = $permission;
				$this->callback = $callback;
			}

			public function usage(){
				$this->temporalSender->sendMessage(Auction::$prefix . "사용법 : " . $this->getUsage() . " - " . $this->getDescription());
			}

			public function message(string $message){
				$this->temporalSender->sendMessage(Auction::$prefix . $message);
			}

			public function number($input){
				return is_numeric($input) and intval($input) == $input;
			}

			public function execute(CommandSender $sender, string $label, array $args) : bool{
				if($this->permission !== "all" && !$sender->hasPermission($this->permission)){
					$sender->sendMessage(Auction::$prefix . "이 명령을 실행할 권한이 없습니다.");
					return true;
				}
				$this->temporalSender = $sender;
				$callback = $this->callback;
				$callback($this, $sender, $args);
				$this->temporalSender = null;
				return true;
			}
		});
	}

	public static function msg($player, $message){
		if(!$player instanceof Player)
			$player = FA::$server->getPlayerExact($player);
		if($player === null)
			return;
		$player->sendMessage(Auction::$prefix . $message);
	}

	public static function broadcast($message){
		FA::$server->broadcastMessage(Auction::$prefix . $message);
	}

	public static function conf($key, $defaultValue){
		return FA::$config->getNested($key, $defaultValue);
	}

	public static function name($o){
		if($o instanceof Item){
			$name = $o->getName();
			if($o->hasEnchantments()){
				$enchs = [];
				foreach($o->getEnchantments() as $ench){
					$enchs[] = $ench->getType()->getName();
				}
				$name .= " " . implode(", ", $enchs);
			}
			$name .= " §r§f" . $o->getCount() . "개";
			return $name;
		}
		return "";
	}
}