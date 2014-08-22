<?php
App::uses('LockableBehavior', 'Lockable.Model/Behavior');
App::uses('Model', 'Model');
App::uses('AppModel', 'Model');

class LockableBehaviorTest extends CakeTestCase {

	/**
	 * Autoload entrypoint for fixtures dependecy solver
	 *
	 * @var string
	 * @access public
	 */
	public $plugin = 'app';

	/**
	 * Test to run for the test case (e.g array('testFind', 'testView'))
	 * If this attribute is not empty only the tests from the list will be executed
	 *
	 * @var array
	 * @access protected
	 */
	protected $_testsToRun = array();

	/**
	 * Fixtures
	 *
	 * @var array
	 * @access public
	 */
	public $fixtures = array(
		//'app.user',
		'core.comment',
	);

	public $LockableBehavior;
	public $Model;

	/**
	 * Start Test callback
	 *
	 * @param string $method
	 * @return void
	 * @access public
	 */
	public function startTest($method) {
		parent::startTest($method);
		$this->LockableBehavior = new LockableBehavior();
		$this->Model = ClassRegistry::init('Comment');
		$this->Model->Behaviors->attach('Lockable');
		$this->Model->validate = array(
		);
	}

	/**
	 * End Test callback
	 *
	 * @param string $method
	 * @return void
	 * @access public
	 */
	public function endTest($method) {
		parent::endTest($method);
		unset($this->LockableBehavior);
		unset($this->Model);
		ClassRegistry::flush();
	}


	public function testSetup() {
		$this->LockableBehavior->setup($this->Model);
	}

	public function testLock() {
		// lockable
		$this->assertTrue($this->Model->lock(1));
		// now unlock
		$this->assertTrue($this->Model->unlock(1));
		// now we can re-lock
		$this->assertTrue($this->Model->lock(1));
		// now unlock (cleanup)
		$this->assertTrue($this->Model->unlock(1));
	}


	// Test that locking actually makes me wait if someone else has the lock.
	/*

		This test no longer works because parent/child share the same database connection,
		so mysql thinks the locks belong to the same person.

	public function testForkLock() {
		// tempFile used for communication between parent and child.
		$tempFile = tmpfile();

		// Split into two..
		$pid = pcntl_fork();
		if ($pid == -1) {
			$this->assertEqual('shit went down. [failed to fork]', 'no shit went down');
			return;
		}
		if ($pid) {
			// I am parent.

			// Wait 10000 microseconds / 10 milliseconds.  Give child time to grab lock.
			usleep(10000);

			$timeBeforeLock = microtime(true);
			// Get lock.
			$this->assertTrue($this->Model->lock(1));
			$timeAfterLock = microtime(true);
			$this->assertTrue($this->Model->unlock(1));

			// How much time elapsed?
			$timeElapsed = $timeAfterLock - $timeBeforeLock;
			// Amount of time elapsed should be (150ms - 10ms + x + y +/- z), where
			// x is the fork time, y is the overhead time from unlockMutex/assert, and z is
			// the "fudge factor" from OS scheduling...

			// Allow range [130, 200] ms
			$this->assertGreaterThanOrEqual(0.130, $timeElapsed);
			$this->assertLessThanOrEqual(   0.200, $timeElapsed);

			// Make sure Child passed tests and delete tempFile
			fseek($tempFile, 0);
			$messageFromChild = fread($tempFile, 1024);
			$this->assertEqual($messageFromChild, 'pass');
			fclose($tempFile);
		} else {
			// I am child.

			// Get lock.
			$lockStatus = $this->Model->lock(1);
			// Hold lock for 150 milliseconds / 150000 microseconds, then unlock
			usleep(150000);
			$unlockStatus = $this->Model->unlock(1);

			// Write test status to temp file, so parent can read it.
			if ($lockStatus === true && $unlockStatus === true) {
				fwrite($tempFile, "pass");
			} else {
				fwrite($tempFile, "fail");
			}

			// Kill self with a "kill" command.
			// If we used exit or die, then CakeTestCase
			// would take over and start to tear down all of the
			// fixtures!  Our parent still needs it.
			posix_kill(posix_getpid(), SIGTERM);
		}
	}
	 */
}
