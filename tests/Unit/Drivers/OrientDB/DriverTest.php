<?php
namespace Spider\Test\Unit\Drivers\OrientDB;

use Codeception\Specify;
use Spider\Commands\Command;
use Spider\Drivers\OrientDB\Driver as OrientDriver;

class DriverTest extends \PHPUnit_Framework_TestCase
{
    use Specify;

    protected $config;
    protected $credentials;

    public function setup()
    {
//        $this->markTestSkipped('The Test Database is not installed');

        $this->credentials = [
            'hostname' => 'localhost',
            'port' => 2424,
            'username' => 'root',
            'password' => "root",
            'database' => 'spider-test'
        ];
    }

    public function testConnections()
    {
        $this->specify("it opens and closes the database without exception", function () {
            $driver = new OrientDriver($this->credentials);
            $driver->open();
            $driver->close();
        });
    }

    public function testReadCommands()
    {
        $this->specify("it selects a single record and returns an array of Records", function () {
            $driver = new OrientDriver($this->credentials);
            $driver->open();

            $response = $driver->executeReadCommand(new Command(
                "SELECT FROM Cat WHERE @rid = #12:0"
            ));

            $driver->close();

            $this->assertInstanceOf('Spider\Drivers\Response', $response, 'failed to return a Response Object');
            $response = $response->getSet();
            $this->assertInstanceOf('Spider\Base\Collection', $response, 'failed to return a Record');
            $this->assertEquals("oreo", $response->name, "failed to return the correct names");
            $this->assertEquals("Cat", $response->label, "failed to return the correct label");
            $this->assertEquals('#12:0', $response->id, "failed to return the correct id");
        });

        $this->specify("it selects multiple unrelated records and returns an array of Records", function () {
            $driver = new OrientDriver($this->credentials);
            $driver->open();

            $response = $driver->executeReadCommand(new Command(
                "SELECT FROM Cat"
            ));

            $driver->close();

            $this->assertInstanceOf('Spider\Drivers\Response', $response, 'failed to return a Response Object');
            $response = $response->getSet();

            $this->assertTrue(is_array($response), "failed to return an array");
            $this->assertCount(2, $response, "failed to return 2 results");
            $this->assertInstanceOf('Spider\Base\Collection', $response[0], 'failed to return Response Object');
        });
    }

    public function testWriteCommands()
    {
        $driver = new OrientDriver($this->credentials);
        $driver->open();

        // Create new
        $query = "INSERT INTO Owner CONTENT " . json_encode(['first_name' => 'nicole', 'last_name' => 'lowman']);
        $response = $driver->executeWriteCommand(new Command($query));

        $this->assertInstanceOf('Spider\Drivers\Response', $response, 'failed to return a Response Object');
        $newRecord = $response->getSet();

        $this->assertInstanceOf('Spider\Base\Collection', $newRecord, 'failed to return a Record');
        $this->assertEquals("nicole", $newRecord->first_name, "failed to return the correct names");

        // Update existing
        $query = "UPDATE (SELECT FROM Owner WHERE @rid=$newRecord->id) MERGE " . json_encode(['last_name' => 'wilson']) . ' RETURN AFTER $current';
        $response = $driver->executeWriteCommand(new Command($query));

        $this->assertInstanceOf('Spider\Drivers\Response', $response, 'failed to return a Response Object');
        $updatedRecord = $response->getSet();

        $this->assertInstanceOf('Spider\Base\Collection', $updatedRecord, 'failed to return a Record');
        $this->assertEquals("wilson", $updatedRecord->last_name, "failed to return the correct names");


        // Delete That one
        $query = "DELETE VERTEX Owner WHERE @rid=$newRecord->id";
        $response = $driver->executeWriteCommand(new Command($query));

        $this->assertInstanceOf('Spider\Drivers\Response', $response, 'failed to return a Response Object');
        $updatedRecord = $response->getSet();

        $this->assertEquals([], $updatedRecord, "failed to delete");

        // And try to get it again
        $response = $driver->executeReadCommand(new Command("SELECT FROM Owner WHERE @rid=$newRecord->id"));

        $this->assertInstanceOf('Spider\Drivers\Response', $response, 'failed to return a Response Object');
        $response = $response->getSet();

        $this->assertTrue(is_array($response), 'failed to return an array');
        $this->assertEmpty($response, "failed to return an EMPTY array");

        // Done
        $driver->close();
    }
}
