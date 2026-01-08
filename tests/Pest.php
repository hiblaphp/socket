<?php

use Hibla\EventLoop\Loop;

uses()
    ->beforeEach(function () {
        Loop::reset();
    })
    ->afterEach(function () {
        Loop::stop();
        Loop::reset();
    })
    ->in(__DIR__)
;