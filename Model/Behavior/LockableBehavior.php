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
	public $mutexes   = [];
	public $mutexInts = [];

	// What is the current lock locked via?
	//    possible values:  false (no lock), 'mutex'
	protected $_currentLockMethod = [];

	protected $_defaults = [
		// lock with Mutex (current machine only, multi-process locked)
		'mutex' => true,
		// Mutex requires an int ID,
		//   we need to "prefix" the ints to make them unique to this Model
		//   this "prefix" should remain consistant on this Model
		'mutexIntPrefix' => 0,
		// Mutex buckets
		//   if > 0, we modulus the mutexInt by this
		//   so that we only have up to X number of buckets per Model
		'mutexIntBuckets' => 100,
	];

	/**
	 * Placeholder... did we ever run Mutex setup (for any model)
	 */
	protected $_mutex_ran_setup = false;

	/**
	 * Configure the behavior through the Model::actsAs property
	 *
	 * @param object $Model
	 * @param array $config
	 */
	public function setup(Model $Model, $config = null) {
		// Ints    - Numeric keys used to identify mutex objects, indexed by model $id
		// Mutexes - Mutex resources, indexed by "ints" or the numeric keys two lines above
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
	 * secure this function against running twice at once
	 *  - pass 1, use a mutext if we can
	 *
	 * @param Model $Model
	 * @param mixed $id
	 * @return boolean $lockObtained
	 * @throws LockException if no locking system available
	 */
	public function lock(Model $Model, $id) {
		// If allowed, try mutex lock and return true on success
		if ($this->settings[$Model->alias]['mutex']) {
			if ($this->lockMutex($Model, $id)) {
				$this->_setCurrentLockMethod($Model, $id, 'mutex');
				return true;
			}
		}

		throw new LockException('LockableBehavior:  Unable to lock with mutex method.');
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

