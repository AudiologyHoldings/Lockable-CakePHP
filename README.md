# Lockable Behavior for CakePHP

Concurrancy locking for any Models, currently using 2 options... you may use
either or both of these, just set them up in the Model when you attach the Behavior

## [Mutex](http://en.wikipedia.org/wiki/Mutual_exclusion)
  / [Semaphore](http://en.wikipedia.org/wiki/Semaphore)
* uses PHP's [sem_get](http://nc1.php.net/manual/en/function.sem-get.php),
  [sem_acquire](http://nc1.php.net/manual/en/function.sem-acquire.php), and
  [sem_release](http://nc1.php.net/manual/en/function.sem-release.php)
* requires PHP [process control extensions](http://nc1.php.net/manual/en/book.posix.php) *(often already included)*
 * for unit testing we also need
   [posix](http://nc1.php.net/manual/en/book.posix.php) and
   [pcntl](http://nc1.php.net/manual/en/book.pcntl.php)

## [Redis](http://redis.io/) via [RedLock](https://github.com/ronnylt/redlock-php)

* uses Redis *(which you may already be using because it is awsome)*
* requires you have set up a
  [Cache config](http://book.cakephp.org/2.0/en/core-libraries/caching.html#configuring-cache-class)
  with the `RedisEngine`

## Install the Plugin

```
cd yourprojectroot
git clone https://github.com/AudiologyHoldings/Lockable-CakePHP.git app/Plugin/Lockable
```

Add the following to your `app/Config/bootstrap.php`

```
CakePlugin::load('Lockable');
```

## Run the Unit Tests

This is a good idea, because it will tell you if you have Redis or Mutex issues.

*(you need both Redis and Mutex setup to run the Unit Tests succesfully)*

```
cd yourprojectroot
cake test Lockable Model/Behavior/LockableBehavior
```

## Add to your Model

```php
<?php
// my Model file
public $actsAs = array(
	// LockableBehavior added with default config
	'Lockable.Lockable' => array(),
);
```

Configuration is simple, just pass in whatever options you want to over-ride
from the default config...

```php
<?php
// my Model file
public $actsAs = array(
	// LockableBehavior passing in your own config (defaults shown)
	'Lockable.Lockable' => array(
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
	)
);
```

## Use from your Model

```php
<?php
// my Model file
public function somethingWithFullModelLocking() {
	$this->lock(1);
	//
	// ... do whatever you need ...
	//
	$this->unlock(1);
	return 'done';
}
public function somethingRequiringRecordSpecificLocking($record) {
	if (empty($record[$this->alias][$this->primaryKey])) {
		throw new OutOfBoundsException('Missing primary key')
	}
	$this->lock($record[$this->alias][$this->primaryKey]);
	//
	// ... do whatever you need ...
	//
	$this->unlock($record[$this->alias][$this->primaryKey]);
	return 'done';
}
```

While a lock exists, no other process can obtain that lock.

The when attempting to obtain that lock, it is "blocking"
(meaning we wait and keep trying to obtain the lock).

* if `unlock` is not called/complete
 * Mutex locks disappear after the process has complete
 * Redis locks disappear after their TTL

### Common Uses

If you have a cron or process which can run on multiple servers at the same
time, you can run into race conditions.

DB locking is a great solution, but only if your DB supports that well, and if
the process you are working on is DB related.  Also some race conditions are
not solvable by DB table/record locking.

This is an option to give you an external means of locking...

It's a **double lock** using first "in process" mutext locking and then
"multi-server" redis locking.   I recommend you use both, because if you care
enough to lock at all, you need to be sure it's really locked out.
