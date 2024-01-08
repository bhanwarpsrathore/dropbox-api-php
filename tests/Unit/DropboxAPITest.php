<?php

/** @var \DropboxAPI\Tests\TestCase $this */

use DropboxAPI\DropboxAPI;
use DropboxAPI\Request;
use DropboxAPI\Session;

it('can be initiated without options', function () {
    $api = new DropboxAPI();

    expect($api)->toBeInstanceOf(DropboxAPI::class);
});

it('can be initiated with options', function () {
    $api = new DropboxAPI([
        'auto_refresh' => true,
        'auto_retry' => true
    ]);

    expect($api)->toBeInstanceOf(DropboxAPI::class);
});

it('can be initiated with Session object', function () {
    $session = new Session('clientId', 'clientSecret');
    $api = new DropboxAPI([], $session);

    expect($api)->toBeInstanceOf(DropboxAPI::class);
});

it('can be initiated with Request object', function () {
    $request = new Request();

    $api = new DropboxAPI([], null, $request);

    expect($api)->toBeInstanceOf(DropboxAPI::class);
});
