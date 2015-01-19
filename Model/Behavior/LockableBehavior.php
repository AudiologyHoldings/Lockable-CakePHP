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

	public $db     = null;
	public $prefix = null;

	/**
	 * Obtains a lock for Model + id combination.
	 *
	 * BLOCKING - If someone else has obtained this lock, this function will sit and wait
	 * (forever) until the lock can be obtained.
	 *
	 * Will throw an exception if there was an error obtaining the lock (out of memory, killed, etc..)
	 *
	 * The lock may be removed by calling ->unlock(), or it will be automatically removed when the script ends.
	 *
	 * @param object $Model CakePHP Model
	 * @param mixed $id     Model.id
	 *
	 * @return boolean $lockObtained (always true)
	 */
	public function lock(Model $Model, $id=0) {
		return $this->lockBlocking($Model, $id);
	}

	/**
	 * Obtains a lock for Model + id combination.
	 *
	 * BLOCKING - If someone else has obtained this lock,
	 *   this function will sit and wait (forever) until the lock can be obtained.
	 *
	 * Will throw an exception if there was an error obtaining the lock (out of memory, killed, etc..)
	 *
	 * The lock may be removed by calling ->unlock(), or it will be automatically removed when the script ends.
	 *
	 * @param object $Model CakePHP Model
	 * @param mixed $id     Model.id
	 *
	 * @return boolean $lockObtained (always true)
	 * @throws LockException
	 */
	public function lockBlocking(Model $Model, $id=0) {
		$locked = $this->_lock($Model, $id, -1);
		if (empty($locked)) {
			// Got NULL/error, throw exception
			throw new LockException('Mysql returned error when obtaining lock');
		}
		return true;
	}

	/**
	 * Obtains a lock for Model + id combination.
	 *
	 * NON-BLOCKING - If someone else has obtained this lock,
	 *   this function will sit and wait for 1 second, and if it can not obtain
	 *   the lock, it will return FALSE
	 *
	 * The lock may be removed by calling ->unlock(), or it will be automatically removed when the script ends.
	 *
	 * @param object $Model CakePHP Model
	 * @param mixed $id     Model.id
	 *
	 * @return boolean $lockObtained
	 */
	public function lockNonBlocking(Model $Model, $id=0) {
		return $this->_lock($Model, $id, -1);
	}

	/**
	 * Obtains a lock for Model + id combination.
	 *
	 * This will either be blocking or non-blocking,
	 *   depending on the $blockingTimeout parameter
	 *
	 * BLOCKING - (timeout = -1) If someone else has obtained this lock,
	 *   this function will sit and wait (forever) until the lock can be obtained.
	 *
	 * NON-BLOCKING - (timeout > -1) If someone else has obtained this lock,
	 *   this function will sit and wait for 1 second, and if it can not obtain
	 *   the lock, it will return FALSE
	 *
	 * The lock may be removed by calling ->unlock(), or it will be automatically removed when the script ends.
	 *
	 * @link http://dev.mysql.com/doc/refman/5.0/en/miscellaneous-functions.html#function_get-lock
	 *
	 * @param object $Model        CakePHP Model
	 * @param mixed $id            Model.id
	 * @param int $blockingTimeout [-1] timeout in seconds. -1 for no timeout
	 *
	 * @return boolean $lockObtained
	 */
	private function _lock(Model $Model, $id=0, $blockingTimeout=-1) {
		// Passing second argument -1 to GET_LOCK = Timeout
		//   -1 = Block/wait forever
		//   1 = Block/wait for 1 second
		$lockResult = $this->db->fetchAll(
			"SELECT COALESCE(GET_LOCK(?, {$blockingTimeout}), 0)",
			["{$this->prefix}.{$Model->alias}.$id"],
			['cache' => false]
		);
		$lockResult = array_values(Hash::flatten($lockResult))[0];

		// Possible return values from MySQL
		// 1: Got lock
		// 0: Timed out waiting for lock (Will never happen, if blockingTimeout = -1)
		// NULL (coalesced into 0): Error (out of memory, killed, etc.)
		return (!empty($lockResult));
	}

	/**
	 * Releases a lock for Model + id combination.
	 *
	 * Returns false if the lock belongs to someone else (we can't unlock it).
	 *
	 * @param object $Model
	 * @param mixed $id Model.id
	 *
	 * @return bool success
	 */
	public function unlock(Model $Model, $id=0) {
		$lockResult = $this->db->fetchAll(
			"SELECT COALESCE(RELEASE_LOCK(?), 1)",
			["{$this->prefix}.{$Model->alias}.$id"],
			['cache' => false]
		);
		$lockResult = array_values(Hash::flatten($lockResult))[0];
		// Possible return values from MySQL
		// 1: Released lock
		// 0: Lock belongs to someone else, we're not allowed to release
		// NULL (coalesced into 1): Lock never existed in the first place
		return (bool)$lockResult;
	}

	/**
	 * Configure the behavior through the Model::actsAs property
	 *
	 * @param object $Model
	 * @param array $config
	 *           $config key 'source' - string name of datasource to use instead of default (a mysql connection in database.php)
	 *           $config key 'prefix' - string prefix used in key names.  we lock on keys that are "$prefix.{$Model->alias}.$id"
	 *
	 * @return void
	 */
	public function setup(Model $Model, $config = null) {
		// Prefix
		$this->prefix = isset($config['prefix']) ? $config['prefix'] : '';

		// Source (db connection)
		if (!empty($config['source'])) {
			try {
				$this->db = ConnectionManager::getDataSource($config['source']);
			} catch (Exception $e) {}
		}
		if (empty($this->db)) {
			$this->db = ConnectionManager::getDataSource('default');
		}

		// Verify it's mysql
		if (stripos($this->db->description, 'MySQL') === false) {
			throw new LockException('Lockable Setup Problem - Lockable requires a mysql connection.');
		}
	}

	/**
	 * Simple reconnect to the database
	 *
	 * Useful for testing because..
	 *
	 *
	 * If you have a lock obtained with GET_LOCK(),
	 * it is released when you execute RELEASE_LOCK(),
	 * execute a new GET_LOCK(), or your connection terminates
	 * (either normally or abnormally).
	 * Locks obtained with GET_LOCK() do not interact with transactions.
	 *
	 * @return void
	 */
	public function rc() {
		$this->db->reconnect();
	}

}

