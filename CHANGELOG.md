# CHANGELOG

## 1.0.2 - 2015-05-15

* Conditionally require functions.php.

## 1.0.1 - 2015-06-24

* Updating EachPromise to call next on the underlying promise iterator as late
  as possible to ensure that generators that generate new requests based on
  callbacks are not iterated until after callbacks are invoked.

## 1.0.0 - 2015-05-12

* Initial release
