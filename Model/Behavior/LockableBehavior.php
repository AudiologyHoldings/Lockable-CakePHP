<?php
/**
 *
 *
 */
if (!class_exists('LockException')) {
	class LockException extends CakeException {
	}
}

App::uses('ModelBehavior', 'Model');
class LockableBehavior extends ModelBehavior {
	public $settings  = [];
	public $locks     = [];
	public $mutexes   = [];
	public $mutexInts = [];

	// What is the current lock locked via?
	//    possible values:  false (no lock), 'mutex', or 'redis
	protected $_currentLockMethod = [];

	protected $_defaults = [
		// lock with Mutex (current machine only, multi-process locked)
		'mutex' => true,
		// lock with Redis (multiple machines, multiple redisServers possible
		'redis' => true,
		// Mutex requires an int ID,
		//   we need to "prefix" the ints to make them unique to this Model
		//   this "prefix" should remain consistant on this Model
		'mutexIntPrefix' => 0,
		// Mutex buckets
		//   if > 0, we modulus the mutexInt by this
		//   so that we only have up to X number of buckets per Model
		'mutexIntBuckets' => 100,
		// Redis Servers to use with RedLock
		//   if empty, we look through all Caches configured for RedisEngine
		//   example:
		//     array(
		//       array('localhost', 6379, 0.01),
		//       array('localhost', 6378, 0.01),
		//       array('localhost', 6377, 0.01),
		//     )
		'redisServers' => array(),
		// Redis can Rety X times...
		//   if you want to BLOCK for a  long time before timeout, set this high [100]
		//   if you want to BLOCK for a short time before timeout, set this low  [3]
		'redisRetryCount' => 100,
		// Redis Retry attempts will be delayed by X milliseconds
		'redisRetryDelay' => 200,
		// Redis Locks need a redisTTL, a nmumber of milliseconds to live for
		//   after this TTL, they auto-unlock
		//     60000 = 60 seconds = 1 min
		'redisTTL' => 60000,
	];

	/**
	 * Placeholder... did we ever run Mutex setup (for any model)
	 */
	protected $_mutex_ran_setup = false;

	/**
	 * Placeholder... did we ever run Redis setup (for any model)
	 */
	protected $_redis_ran_setup = false;

	/**
	 * Configure the behavior through the Model::actsAs property
	 *
	 * @param object $Model
	 * @param array $config
	 */
	public function setup(Model $Model, $config = null) {
		// Ints    - Numeric keys used to identify mutex objects, indexed by model $id
		// Locks   - Redis lock objects, indexed by model $id
		// Mutexes - Mutex resources, indexed by "ints" or the numeric keys two lines above
		$this->locks[$Model->alias]   = array();
		$this->mutexes[$Model->alias] = array();
		$this->mutexInts[$Model->alias]    = array();

		if (is_array($config)) {
			$this->settings[$Model->alias] = array_merge($this->_defaults, $config);
		} else {
			$this->settings[$Model->alias] = $this->_defaults;
		}

		// default the alias
		if (empty($this->settings[$Model->alias]['alias'])) {
			$this->settings[$Model->alias]['alias'] = $Model->alias;
		}

		// validate the mutexIntPrefix Config
		if (empty($this->settings[$Model->alias]['mutexIntPrefix'])) {
			$this->settings[$Model->alias]['mutexIntPrefix'] = Configure::read('mutexIntPrefix.' . $Model->alias);
		}
		if (empty($this->settings[$Model->alias]['mutexIntPrefix'])) {
			$this->settings[$Model->alias]['mutexIntPrefix'] = Configure::read('mutex_prefix.' . $Model->alias);
		}
		if (empty($this->settings[$Model->alias]['mutexIntPrefix'])) {
			// "fake" it by doing a md5() of the Model->alias, and using whatever numbers are in it
			$this->settings[$Model->alias]['mutexIntPrefix'] = $this->strtoint($Model->alias);
		}
		if (!is_numeric($this->settings[$Model->alias]['mutexIntPrefix'])) {
			throw new LockException('Lockable Setup Problem - mutexIntPrefix must be an integer.');
		}

		// if redisTTL < 1, set to 10
		if (empty($this->settings[$Model->alias]['redisTTL']) || (int)$this->settings[$Model->alias]['redisTTL'] < 10) {
			$this->settings[$Model->alias]['redisTTL'] = 10;
		}
	}

	/**
	 * Runs on first try of locking via Mutex
	 * Verifies required functions
	 *
	 * If failed, $setting['mutex'] is toggled to false and an error is logged.
	 *
	 * @param object $Model
	 * @return boolean
	 */
	public function setupMutex(Model $Model) {
		if (function_exists('sem_get') && function_exists('sem_acquire') && empty($this->fakeMissingSem)) {
			return true;
		}
		$this->settings[$Model->alias]['mutex'] = false;
		$this->log('LockableBehavior: Exception [%s].  Mutex Lock disabled.', 'error');
		return false;
	}

	/**
	 * Runs on first try of locking via Redis.
	 * Attempts to set up a RedLock object.
	 *
	 * If failed, $setting['redis'] is toggled to false and an error is logged.
	 *
	 * @param object $Model
	 * @return boolean
	 */
	public function setupRedis(Model $Model) {
		$error = '';
		try {
			$RedLock = $this->getRedLockObject($Model);
		} catch (Exception $e) {
			$error = $e->getMessage();
		}
		if (!empty($RedLock) && is_object($RedLock)) {
			return true;
		}
		$this->settings[$Model->alias]['redis'] = false;
		$this->log("LockableBehavior: Exception [%s].  Redis Lock disabled. $error", 'error');
		return false;
	}

	/**
	 * secure this function against running twice at once
	 *  - pass 1, use a mutext if we can
	 *  - pass 2, use a redis lock if we can
	 *
	 * @param Model $Model
	 * @param mixed $id
	 * @return boolean $lockObtained
	 * @throws LockException if no locking system available
	 */
	public function lock(Model $Model, $id) {
		// If allowed, try redis lock and return true on success
		if ($this->settings[$Model->alias]['redis']) {
			try {
				if ($this->lockRedis($Model, $id)) {
					$this->_setCurrentLockMethod($Model, $id, 'redis');
					return true;
				}
			} catch (Exception $e) {
				if (class_exists('AppLog')) {
					AppLog::error('Exception caught while trying to lock Redis: ' . $e->getMessage());
				}
				if (!$this->settings[$Model->alias]['mutex']) {
					throw new LockException('LockableBehavior:  Unable to lock with redis. Got exception:' . $e->getMessage());
				} else {
					// Falling back to mutex.
				}
			}
		}

		// If allowed, try mutex lock and return true on success
		if ($this->settings[$Model->alias]['mutex']) {
			if ($this->lockMutex($Model, $id)) {
				$this->_setCurrentLockMethod($Model, $id, 'mutex');
				return true;
			}
		}

		throw new LockException('LockableBehavior:  Unable to lock with either redis or mutex method.');
	}

	/**
	 * Add entry in __currentLockMethod for $Model + $id ===> $method
	 *
	 * @param Model $Model
	 * @param int $id
	 * @param string or false $method
	 * @return boolean true
	 */
	public function _setCurrentLockMethod(Model $Model, $id, $method) {
		if (!array_key_exists($Model->alias, $this->_currentLockMethod) || !is_array($this->_currentLockMethod[$Model->alias])) {
			$this->_currentLockMethod[$Model->alias] = [];
		}
		$this->_currentLockMethod[$Model->alias][$id] = $method;
		return true;
	}

	/**
	 * secure this function against running twice at once
	 * secure against double-run requests by using mutex
	 *
	 * If the script dies unexpectedly,
	 * the mutex will be cleared by the auto_release setting,
	 * which is set by default.
	 *
	 * @param Model $Model
	 * @param int $id
	 * @return boolean $lockObtained or $skipped
	 * @throws LockException if PHP semaphore doesn't work right (sanity)
	 */
	public function lockMutex(Model $Model, $id) {
		if (empty($this->_mutex_ran_setup)) {
			// Run setup on first lock.
			//   Not in setup() for performance.
			$this->setupMutex($Model);
			$this->_mutex_ran_setup = true;
		}

		if (!$this->settings[$Model->alias]['mutex']) {
			return false;
		}

		// Detemine int for MUTEX based on $id
		$mutexInt = $this->lockInt($Model, $id);
		if (empty($mutexInt) || !is_numeric($mutexInt)) {
			// This shouldn't ever happen.
			throw new LockException('Unable to get the Int for Lock via Mutex.');
		}

		// BEGIN obtaining MUTEX
		$mutex = sem_get($mutexInt);
		if (!is_resource($mutex)) {
			// This shouldn't ever happen. (Mutex semaphore doesn't exist)
			throw new LockException('Unable to setup transaction Lock via Mutex.');
		}
		// BLOCKS and waits for mutex
		if (!sem_acquire($mutex)) {
			// This shouldn't ever happen. (Mutex semaphore unable to be aquired)
			throw new LockException('Unable to aquired transaction Lock via Mutex.');
		}

		// assign this Resource to a vairable on the Model (so it "remains" persistent)
		$this->mutexes[$Model->alias][$mutexInt] = $mutex;

		return true;
	}

	/**
	 * We'd like out locking to be consistant across multiple application servers...
	 * the easiest way is to set it up in Redis with a datetime stamp
	 *
	 * this is a "double-check" which should be done after the semaphore has been aquired
	 *
	 * @param Model $Model
	 * @param mixed $id
	 * @return boolean
	 */
	public function lockRedis(Model $Model, $id) {
		if (empty($this->_redis_ran_setup)) {
			// Run setup on first lock.
			//   Not in setup() for performance.
			$this->setupRedis($Model);
			$this->_redis_ran_setup = true;
		}

		if (!$this->settings[$Model->alias]['redis']) {
			// Must return true so lock() tries other methods.
			return true;
		}

		$id = trim(strval($id));
		$cacheKey = $Model->alias . '_lock_' . $id;
		$redisTTL = $this->settings[$Model->alias]['redisTTL'];

		// BLOCKS and waits for mutex
		$lock = $this->getRedLockObject($Model)->lock($cacheKey, $redisTTL);
		if (empty($lock) || empty($lock['token'])) {
			// This shouldn't ever happen. (Redis lock unable to be aquired)
			throw new LockException('Unable to aquire transaction Lock via Redis.');
		}

		// save this lock
		$this->locks[$Model->alias][$id] = $lock;

		return true;
	}

	/**
	 * let go of the mutex, cleanup, and carry on
	 * delete the Redis lock key
	 *
	 * @param Model $Model
	 * @param mixed $id
	 * @return boolean
	 */
	public function unlock($Model, $id) {
		if (empty($this->_currentLockMethod[$Model->alias][$id])) {
			// Was never locked in the first place
			return true;
		}

		if ($this->_currentLockMethod[$Model->alias][$id] == 'redis') {
			if (!$this->unlockRedis($Model, $id)) {
				return false;
			}
		}

		if ($this->_currentLockMethod[$Model->alias][$id] == 'mutex') {
			if (!$this->unlockMutex($Model, $id)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * let go of the mutex, cleanup, and carry on
	 *
	 * @param Model $Model
	 * @param mixed $id
	 * @return boolean
	 */
	public function unlockMutex(Model $Model, $id) {
		if (!$this->settings[$Model->alias]['mutex']) {
			// Must return true so unlock() tries other methods.
			return true;
		}

		$mutexInt = $this->lockInt($Model, $id);
		if (empty($mutexInt) || !is_numeric($mutexInt)) {
			throw new LockException('Unable to get the Int for Lock via Mutex.');
		}

		$mutexInt = trim(strval($mutexInt));
		if (empty($this->mutexes[$Model->alias][$mutexInt])) {
			// lock not found :(
			return false;
		}

		$mutex = $this->mutexes[$Model->alias][$mutexInt];

		if (!is_resource($mutex)) {
			// This shouldn't ever happen. (Mutex semaphore doesn't exist)
			throw new LockException('Unable to setup transaction Lock via Mutex.');
		}

		// actually release the aquired semaphore lock
		//   fail silently
		@sem_release($mutex);

		// remove the mutex Resource to finish the release
		//   important to the mutex release processing
		unset($mutex, $this->mutexes[$Model->alias][$mutexInt]);

		return true;
	}

	/**
	 * delete the Redis lock
	 *
	 * @param Model $Model
	 * @param mixed $id
	 * @return boolean
	 */
	public function unlockRedis(Model $Model, $id) {
		if (!$this->settings[$Model->alias]['redis']) {
			// Must return true so unlock() tries other methods.
			return true;
		}

		$id = trim(strval($id));
		if (empty($this->locks[$Model->alias][$id])) {
			// lock not found :(
			return false;
		}

		// doesn't return anything, so we just assume...
		$this->getRedLockObject($Model)->unlock($this->locks[$Model->alias][$id]);

		// remove the locks Configuration
		unset($this->locks[$Model->alias][$id]);

		return true;
	}

	/**
	 * get the RedLock object, ready for Redis Locking
	 *
	 * @param Model $Model
	 * @return object $this->RedLock
	 */
	public function getRedLockObject(Model $Model) {
		if (!empty($this->RedLock)) {
			return $this->RedLock;
		}

		App::import('Lib', 'Lockable.RedLock');
		$this->RedLock = new RedLock(
			$this->getRedLockServers($Model),
			$this->settings[$Model->alias]['redisRetryDelay'],
			$this->settings[$Model->alias]['redisRetryCount']
		);
		return $this->RedLock;
	}

	/**
	 * setup "servers" for RedLock
	 *   it should support multiple servers,
	 *   but will work ok with just one
	 *
	 * look for already setup/configed list in:
	 *   $this->settings[$Model->alias]['redisServers'];
	 *
	 * if not found, get from Cache config
	 *   it will use all uniquely configured Redis Cache configs
	 *
	 * RedLock uses a quorum, from odd numbers of servers
	 *   but it will work find with just one server too
	 *
	 * @param Model $Model
	 * @return array $servers
	 */
	public function getRedLockServers(Model $Model) {
		if (!empty($this->settings[$Model->alias]['redisServers'])) {
			return $this->settings[$Model->alias]['redisServers'];
		}

		// get config from Cache Config
		$configs = Cache::configured();
		$servers = [];
		foreach ($configs as $name) {
			$config = Cache::config($name);
			if (empty($config['engine']) || $config['engine'] != 'Redis') {
				continue;
			}
			if (empty($config['settings']['server'])) {
				$this->log("Unable to setup Redis Lock: Cache config with RedisEngine is missing the 'server' for config {$name}");
				continue;
			}
			if (empty($config['settings']['port'])) {
				$this->log("Unable to setup Redis Lock: Cache config with RedisEngine is missing the 'port' for config {$name}");
				continue;
			}
			// use a key, so we know we are not duplicating configs falsely
			$key = $config['settings']['server'] . $config['settings']['port'];
			$servers[$key] = array(
				$config['settings']['server'],
				$config['settings']['port'],
				0.01,
			);
		}
		if (empty($servers)) {
			throw new LockException('Unable to setup Redis Lock: Cache config with RedisEngine not set up.');
		}

		// stash for future use & debugging
		$servers = array_values($servers);
		$this->settings[$Model->alias]['redisServers'] = $servers;

		return $servers;
	}

	/**
	 * Gets an (int) for use on a mutext
	 *  - gets a prefix (unique to the Model, or Site or whatever)
	 *  - ensures the ID is an integer, if not, it extracts the numeric values
	 *  - adds the prefix to the ID
	 *
	 * @param mixed $id Model.id
	 * @return int $lockInt
	 */
	public function lockInt(Model $Model, $id) {
		$id = trim(strval($id));

		// do we already have a "stashed" int for this $id?
		if (!array_key_exists($Model->alias, $this->mutexInts)) {
			$this->mutexInts[$Model->alias] = array();
		}
		if (array_key_exists($id, $this->mutexInts[$Model->alias])) {
			return $this->mutexInts[$Model->alias][$id];
		}

		// get the int val for this ID
		$mutexInt = $this->strtoint($id);

		// reduce the mutexInt to 1 out of 10
		//   so we don't blow up RAM with 1000s of unique locks
		if (!empty($this->settings[$Model->alias]['mutexIntBuckets'])) {
			$modulus = $this->settings[$Model->alias]['mutexIntBuckets'];
			$mutexInt = ($mutexInt % $modulus);
		}

		// prepend the prefix
		$prefix = $this->settings[$Model->alias]['mutexIntPrefix'];
		$mutexInt = intval(strval($prefix) . strval($mutexInt));

		// stash this int, for future use
		$this->mutexInts[$Model->alias][$id] = $mutexInt;

		return $mutexInt;
	}


	/**
	 * Create a consistant integer for any string
	 *   entering the string multiple times will result in the same integer
	 *   but the integer should be somewhat unique as well
	 *
	 * if the string is numeric, we just return that number
	 * otherwise, we hash the string, extract the numerals, and return an 10 digit int
	 *
	 * @param string $string
	 * @return int $uniqueIshInt
	 */
	private function strtoint($string) {
		$string = trim(strval($string));
		if (is_numeric($string)) {
			return intval(str_replace('.', '', $string));
		}

		$hashes = array(
			$string,
			md5($string),
			md5(substr($string, 0, 3)),
			md5(substr($string, 0, 5)),
			md5(substr($string, 0, 7)),
			md5(substr($string, -3)),
			md5(substr($string, -5)),
			md5(substr($string, -7)),
		);

		$uniqueIshInt = preg_replace('#[^0-9]#', '', implode('', $hashes));
		$uniqueIshInt = substr($uniqueIshInt, 0, 10);

		return intval($uniqueIshInt);
	}

	/**
	 * public alias of strtoint()
	 *
	 * @param Model $Model
	 * @param string $string
	 * @return int $uniqueIshInt
	 */
	public function lockableStrtoint(Model $Model, $string) {
		return $this->strtoint($string);
	}


}

