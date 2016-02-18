<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class UserTest extends TestCase
{
	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		Artisan::call('db:seed');
	}

	public function testAdministratorFlag()
	{
		$user = new \App\Models\User;

		$user->setAdministrator(false);

		$this->assertEquals(false, $user->isAdministrator());

		$user->setAdministrator(true);

		$this->assertEquals(true, $user->isAdministrator());

		$user->setAdministrator(false);

		$this->assertEquals(false, $user->isAdministrator());
	}
}