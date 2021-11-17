<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

class Compare
{
    protected $test = null;

    public function __construct($test)
    {
        $this->test = $test;
    }

    public function object($object, array $expectedValues)
    {
        $values = json_decode(json_encode($object), true);
        $this->test->assertIsArray($values);

        foreach ($expectedValues as $key => $value)
        {
            $this->compare($values, $expectedValues, $key);
        }
    }

    public function compare(array $values, array $expectedValues, string $key)
    {
        $this->test->assertArrayHasKey($key, $values);

        if (is_array($expectedValues[$key]))
        {
            $this->test->assertIsArray($values[$key], $key);

            foreach ($expectedValues[$key] as $k => $value)
            {
                $this->compare($values[$key], $expectedValues[$key], $k);
            }
        }
        else
            $this->test->assertEquals($expectedValues[$key], $values[$key], $key);
    }
}
