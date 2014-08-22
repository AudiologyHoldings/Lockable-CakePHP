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

	public $db = null;

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
	 * @param object $Model
	 * @param mixed $id Model.id
	 * @return bool success (always true)
	 */
	public function lock(Model $Model, $id=0) {
		logDebugP("lock called");
		$lockResult = $this->db->fetchAll(
			"SELECT COALESCE(GET_LOCK(?, -1), 0)",
			["{$Model->alias}.$id"],
			['cache' => false]
		);
		$lockResult = array_values(Hash::flatten($lockResult))[0];
		// Passing second argument -1 to GET_LOCK = Timeout = Block/wait forever

		// Possible return values from MySQL
		// 1: Got lock
		// 0: Timed out waiting for lock (Will never happen)
		// NULL (coalesced into 0): Error (out of memory, killed, etc.)
		if (empty($lockResult)) {
			// Got NULL/error, throw exception
			throw new LockException('Mysql returned error when obtaining lock');
		}
		return (bool)$lockResult;
	}

	/**
	 * Releases a lock for Model + id combination.
	 *
	 * Returns false if the lock belongs to someone else (we can't unlock it).
	 *
	 * @param object $Model
	 * @param mixed $id Model.id
	 * @return bool success
	 */
	public function unlock(Model $Model, $id=0) {
		logDebugP("unlock called");
		$lockResult = $this->db->fetchAll(
			"SELECT COALESCE(RELEASE_LOCK(?), 1)",
			["{$Model->alias}.$id"],
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
	 */
	public function setup(Model $Model, $config = null) {
		$this->db = $Model->getDataSource();
		if (stripos($this->db->description, 'MySQL') === false) {
			throw new LockException('Lockable Setup Problem - Lockable requires a mysql connection.');
		}
	}

	public function rc() {
		$this->db->reconnect();
	}

}

