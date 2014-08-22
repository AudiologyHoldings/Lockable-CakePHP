# Lockable Behavior for CakePHP

Simple concurrancy locking for any Model using MySQL's GET_LOCK() and RELEASE_LOCK().
It requires use of MySQL.

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
	'Lockable.Lockable',
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

* if `unlock` is not called, The lock dissappears after the process has completed

### Common Uses

If you have a cron or process which can run on multiple servers at the same
time, you can run into race conditions.
