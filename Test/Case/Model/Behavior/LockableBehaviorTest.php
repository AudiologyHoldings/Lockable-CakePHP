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
		$this->Model->Behaviors->attach('Lockable', array(
			'mutex' => true,
			'redis' => true,
			'mutexIntPrefix' => 1111,
			'mutexIntBuckets' => 100,
			'redisTTL' => 60000,
			'redisRetryCount' => 100, // long timeout lock
		));
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
		$this->assertEqual(
			$this->LockableBehavior->settings[$this->Model->alias]['alias'],
			$this->Model->alias
		);
		$this->assertEqual(
			$this->LockableBehavior->settings[$this->Model->alias]['mutexIntPrefix'],
			840695182 // auto-generated from Model->alias
		);
		$this->assertEqual(
			$this->LockableBehavior->settings[$this->Model->alias]['redisTTL'],
			60000
		);

		$this->LockableBehavior->setup($this->Model, array(
			'mutexIntPrefix' => 1111,
			'redisTTL' => 1,
		));
		$this->assertEqual(
			$this->LockableBehavior->settings[$this->Model->alias]['redisTTL'],
			10
		);
		$this->assertEqual(
			$this->LockableBehavior->settings[$this->Model->alias]['mutexIntPrefix'],
			1111
		);

		try {
			$this->LockableBehavior->setup($this->Model, array(
				'mutexIntPrefix' => 'aaaa',
			));
			$this->assertEqual(
				'Exception Not Thrown',
				'We wanted a LockException'
			);
		} catch (LockException $e) {
			$this->assertEqual(
				$e->getMessage(),
				'Lockable Setup Problem - mutexIntPrefix must be an integer.'
			);
		}
	}

	public function testSetupRedis() {
		$this->LockableBehavior->setup($this->Model);
		$this->assertTrue(
			$this->LockableBehavior->setupRedis($this->Model)
		);

		$mock = $this->getMock('LockableBehavior', ['getRedLockObject']);
		$mock->expects($this->any())
			->method('getRedLockObject')
			->will($this->returnValue(false));
		$mock->setup($this->Model);
		$this->assertTrue($mock->settings[$this->Model->alias]['redis']);
		$this->assertFalse($mock->setupRedis($this->Model));
		$this->assertFalse($mock->settings[$this->Model->alias]['redis']);

		// TOOD throw exception and catch it
	}

	public function testSetupMutex() {
		$this->LockableBehavior->setup($this->Model);
		$this->assertTrue(
			$this->LockableBehavior->setupMutex($this->Model)
		);

		$mock = $this->getMock('LockableBehavior', ['getRedLockObject']);
		$mock->fakeMissingSem = true;
		$mock->setup($this->Model);
		$this->assertTrue($mock->settings[$this->Model->alias]['mutex']);
		$this->assertFalse($mock->setupMutex($this->Model));
		$this->assertFalse($mock->settings[$this->Model->alias]['mutex']);

		// TOOD throw exception and catch it
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

		$mock = $this->getMock('LockableBehavior', ['lockMutex']);
		$mock->expects($this->any())
			->method('lockMutex')
			->will($this->returnValue(false));
		$mock->setup($this->Model);
		$this->assertFalse($mock->lock($this->Model, 1));

		$mock = $this->getMock('LockableBehavior', ['lockMutex', 'lockRedis']);
		$mock->expects($this->any())
			->method('lockMutex')
			->will($this->returnValue(true));
		$mock->expects($this->any())
			->method('lockRedis')
			->will($this->returnValue(false));
		$mock->setup($this->Model);
		$this->assertFalse($mock->lock($this->Model, 1));

		$mock = $this->getMock('LockableBehavior', ['lockMutex', 'lockRedis']);
		$mock->expects($this->any())
			->method('lockMutex')
			->will($this->returnValue(false));
		$mock->expects($this->any())
			->method('lockRedis')
			->will($this->returnValue(false));
		$mock->setup($this->Model);
		$this->assertFalse($mock->lock($this->Model, 1));

		$mock->settings[$this->Model->alias]['mutex'] = false;
		$mock->settings[$this->Model->alias]['redis'] = false;

		try {
			$mock->lock($this->Model, 1);
			$this->assertEqual(
				'Exception Not Thrown',
				'We wanted a LockException'
			);
		} catch (LockException $e) {
			$this->assertEqual(
				$e->getMessage(),
				'LockableBehavior:  Deep trouble.  Neither redis or semaphore locks are working.'
			);
		}

	}

	public function testLockMutex() {
		// lockable
		$this->assertTrue($this->Model->lockMutex(1));
		// now unlock
		$this->assertTrue($this->Model->unlockMutex(1));
		// now we can re-lock
		$this->assertTrue($this->Model->lockMutex(1));
		// now unlock (cleanup)
		$this->assertTrue($this->Model->unlockMutex(1));

		// look for testForkLockMutex, verify that mutexes are locking

		$mock = $this->getMock('LockableBehavior', ['lockInt']);
		$mock->expects($this->any())
			->method('lockInt')
			->will($this->returnValue(false));
		$mock->setup($this->Model);

		// bypass if not allowed in settings
		$mock->settings[$this->Model->alias]['mutex'] = false;
		$this->assertTrue($mock->unlockMutex($this->Model, 1));

		// allow, now throw exception due to mocked lockInt()
		$mock->settings[$this->Model->alias]['mutex'] = true;
		try {
			$mock->lockMutex($this->Model, 1);
			$this->assertEqual(
				'Exception Not Thrown',
				'We wanted a LockException'
			);
		} catch (LockException $e) {
			$this->assertEqual(
				$e->getMessage(),
				'Unable to get the Int for Lock via Mutex.'
			);
		}

	}

	public function testUnlock() {
		// lockable
		$this->assertTrue($this->Model->lock(1));
		// now unlock
		$this->assertTrue($this->Model->unlock(1));
		// now we can re-lock
		$this->assertTrue($this->Model->lock(1));
		// now unlock (cleanup)
		$this->assertTrue($this->Model->unlock(1));

		$mock = $this->getMock('LockableBehavior', ['unlockMutex']);
		$mock->expects($this->any())
			->method('unlockMutex')
			->will($this->returnValue(false));
		$mock->setup($this->Model);
		$this->assertFalse($mock->unlock($this->Model, 1));

		$mock = $this->getMock('LockableBehavior', ['unlockMutex', 'unlockRedis']);
		$mock->expects($this->any())
			->method('unlockMutex')
			->will($this->returnValue(true));
		$mock->expects($this->any())
			->method('unlockRedis')
			->will($this->returnValue(false));
		$mock->setup($this->Model);
		$this->assertFalse($mock->unlock($this->Model, 1));
	}
	public function testUnlockMutex() {
		// id doesn't exist = false
		$this->assertFalse($this->Model->unlockMutex(9999));
		// make lock
		$this->assertTrue($this->Model->lockMutex(1));
		// not unlock
		$this->assertTrue($this->Model->unlockMutex(1));
		// secondary calls are now false (id doesn't exist)
		$this->assertFalse($this->Model->unlockMutex(1));
		// make lock again to prove worked
		$this->assertTrue($this->Model->lockMutex(1));
		// now unlock (cleanup)
		$this->assertTrue($this->Model->unlockMutex(1));

		$mock = $this->getMock('LockableBehavior', ['lockInt']);
		$mock->expects($this->any())
			->method('lockInt')
			->will($this->returnValue(false));
		$mock->setup($this->Model);

		// bypass if not allowed in settings
		$mock->settings[$this->Model->alias]['mutex'] = false;
		$this->assertTrue($mock->lockMutex($this->Model, 1));

		// allow, now throw exception due to mocked lockInt()
		$mock->settings[$this->Model->alias]['mutex'] = true;
		try {
			$mock->lockMutex($this->Model, 1);
			$this->assertEqual(
				'Exception Not Thrown',
				'We wanted a LockException'
			);
		} catch (LockException $e) {
			$this->assertEqual(
				$e->getMessage(),
				'Unable to get the Int for Lock via Mutex.'
			);
		}

	}

	public function testLockRedis() {
		// lockable
		$this->assertTrue($this->Model->lockRedis(1));
		// now unlock
		$this->assertTrue($this->Model->unlockRedis(1));
		// now we can re-lock
		$this->assertTrue($this->Model->lockRedis(1));
		// now unlock (cleanup)
		$this->assertTrue($this->Model->unlockRedis(1));
		// look for testForkLockRedis, verify that mutexes are locking

		$mockRedLock = $this->getMock('RedLock', ['lock'], [[]]);
		$mockRedLock->expects($this->any())
			->method('lock')
			->will($this->returnValue(false));
		$mock = $this->getMock('LockableBehavior', ['getRedLockObject']);
		$mock->expects($this->any())
			->method('getRedLockObject')
			->will($this->returnValue($mockRedLock));
		$mock->setup($this->Model);

		// bypass if not allowed in settings
		$mock->settings[$this->Model->alias]['redis'] = false;
		$this->assertTrue($mock->lockRedis($this->Model, 1));

		// allow, now throw exception due to mocked lockInt()
		$mock->settings[$this->Model->alias]['redis'] = true;
		try {
			$mock->lockRedis($this->Model, 1);
			$this->assertEqual(
				'Exception Not Thrown',
				'We wanted a LockException'
			);
		} catch (LockException $e) {
			$this->assertEqual(
				$e->getMessage(),
				'Unable to aquire transaction Lock via Redis.'
			);
		}
	}

	public function testUnlockRedis() {
		// id doesn't exist = false
		$this->assertFalse($this->Model->unlockRedis(9999));
		// make lock
		$this->assertTrue($this->Model->lockRedis(1));
		// not unlock
		$this->assertTrue($this->Model->unlockRedis(1));
		// secondary calls are now false (id doesn't exist)
		$this->assertFalse($this->Model->unlockRedis(1));
		// make lock again to prove worked
		$this->assertTrue($this->Model->lockRedis(1));
		// now unlock (cleanup)
		$this->assertTrue($this->Model->unlockRedis(1));

		$mock = $this->getMock('LockableBehavior', ['lock']);
		$mock->expects($this->any())
			->method('lock')
			->will($this->returnValue(true));
		$mock->setup($this->Model);

		// bypass if not allowed in settings
		$mock->settings[$this->Model->alias]['redis'] = false;
		$this->assertTrue($mock->unlockRedis($this->Model, 1));

		// allow, now throw exception due to mocked lockInt()
		$mock->settings[$this->Model->alias]['redis'] = true;

		// doesn't have the lock setup
		$this->assertFalse($mock->unlockRedis($this->Model, 1));
		$mock->locks[$this->Model->alias][1] = false;
		$this->assertFalse($mock->unlockRedis($this->Model, 1));

		// setup a fake lock, fails gracefully now
		$mock->locks[$this->Model->alias][1] = ['resource' => null, 'token' => 'abc'];
		$this->assertTrue($mock->unlockRedis($this->Model, 1));
	}


	public function testGetRedLock() {
		$redlock = $this->Model->getRedLockObject();
		$this->assertTrue(is_object($redlock));
		$this->assertEqual(get_class($redlock), 'RedLock');
	}


	public function testGetRedLockServers() {
		/*
		$servers = $this->Model->getRedLockServers();
		$this->assertTrue(is_array($servers));
		$this->assertTrue(count($servers) > 0);
		$this->assertTrue(array_key_exists(0, $servers));
		$this->assertTrue(is_array($servers[0]));
		$this->assertEqual(count($servers[0]), 3);
		 */

		$mock = $this->getMock('LockableBehavior', ['lock']);
		$this->setup($this->Model);
		$expect = array(
			array('localhost', 6379, 0.01),
			array('127.0.0.1', 6379, 0.01),
		);
		$mock->settings[$this->Model->alias]['redisServers'] = $expect;
		$this->assertEqual(
			$mock->getRedLockServers($this->Model),
			$expect
		);

	}

	// Test that locking actually makes me wait if someone else has the lock.
	public function testForkLockMutex() {
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
			$this->assertTrue($this->Model->lockMutex(1));
			$timeAfterLock = microtime(true);
			$this->assertTrue($this->Model->unlockMutex(1));

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
			$lockStatus = $this->Model->lockMutex(1);
			// Hold lock for 150 milliseconds / 150000 microseconds, then unlock
			usleep(150000);
			$unlockStatus = $this->Model->unlockMutex(1);

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

	// Test that locking actually makes me wait if someone else has the lock.
	public function testForkLockRedis() {
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
			$this->assertTrue($this->Model->lockRedis(1));
			$timeAfterLock = microtime(true);
			$this->assertTrue($this->Model->unlockRedis(1));

			// How much time elapsed?
			$timeElapsed = $timeAfterLock - $timeBeforeLock;
			// Amount of time elapsed should be (300ms - 10ms + x + y +/- z + Ω), where
			// x is the fork time, y is the overhead time from unlockRedis, and z is
			// the "fudge factor" from OS scheduling...
			// ( and Ω is the overhead of waiting for a redis script/network latency, can be high! )

			// Allow range [260, 500] ms
			$this->assertGreaterThanOrEqual(0.260, $timeElapsed);
			$this->assertLessThanOrEqual(   0.500, $timeElapsed);

			// Make sure Child passed tests and delete tempFile
			fseek($tempFile, 0);
			$messageFromChild = fread($tempFile, 1024);
			$this->assertEqual($messageFromChild, 'pass');
			fclose($tempFile);
		} else {
			// I am child.

			// Get lock.
			$lockStatus = $this->Model->lockRedis(1);
			// Hold lock for 300 milliseconds / 300000 microseconds, then unlock
			usleep(300000);
			$unlockStatus = $this->Model->unlockRedis(1);

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

	public function testLockInt() {
		$this->assertEqual(
			$this->Model->lockInt(1),
			11111
		);
		$this->assertEqual(
			$this->Model->lockInt(999999),
			intval('1111' . (999999 % 100))
		);
		$this->assertEqual(
			$this->Model->lockInt('999999'),
			intval('1111' . (999999 % 100))
		);
		$this->assertEqual(
			$this->Model->lockInt(999.999),
			intval('1111' . (999999 % 100))
		);
		$this->assertEqual(
			$this->Model->lockInt('99.9999'),
			intval('1111' . (999999 % 100))
		);

		$this->assertEqual(
			$this->Model->lockInt('abcd'),
			111193
		);
		$this->assertEqual(
			$this->Model->lockInt('abcde'),
			111113
		);
		$this->assertEqual(
			$this->Model->lockInt('abc'),
			111132
		);
		$this->assertEqual(
			$this->Model->lockInt('ab.cd'),
			11112
		);
		$this->assertEqual(
			$this->Model->lockInt('foobar'),
			11113
		);
		$this->assertEqual(
			$this->Model->lockInt('387b5450-0850-11e4-9191-0800200c9a66'),
			111185
		);
	}

	public function testStringtoint() {
		$this->assertEqual(
			$this->Model->lockableStrtoint(1),
			1
		);
		$this->assertEqual(
			$this->Model->lockableStrtoint('01230'),
			1230
		);
		$this->assertEqual(
			$this->Model->lockableStrtoint(' 01230 '),
			1230
		);
		$this->assertEqual(
			$this->Model->lockableStrtoint('a'),
			175901683
		);
		$this->assertEqual(
			$this->Model->lockableStrtoint(' aaaa '),
			7487337454
		);
		$this->assertEqual(
			$this->Model->lockableStrtoint('aaaa'),
			7487337454
		);
		$this->assertEqual(
			$this->Model->lockableStrtoint('387b5450-0850-11e4-9191-0800200c9a66'),
			3875450085
		);
	}

}
