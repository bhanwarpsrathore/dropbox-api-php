<?php

/** @var \DropboxAPI\Tests\TestCase $this */

use DropboxAPI\Session;

it('can be initiated with client id', function () {
    $session = new Session('client_id');

    expect($session)->toBeInstanceOf(Session::class);
});

it('can be initiated with client id and secret', function () {
    $session = new Session('client_id', 'client_secret');

    expect($session)->toBeInstanceOf(Session::class);
});

it('it can be initiated with client id, secret and redirect uri', function () {
    $session = new Session('client_id', 'client_secret', 'redirect_uri');

    expect($session)->toBeInstanceOf(Session::class);
});
