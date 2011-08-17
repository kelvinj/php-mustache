<?php

class CompilingMustacheSpecsTests
{
	protected $tests = array();

	public function construct()
	{
	}

	public function loadTests($specs_dir)
	{
		foreach(glob($specs_dir . '/*.json') as $json_file)
		{
			$data = json_decode(file_get_contents($json_file));

			if($data)
			{
				$name_prefix = pathinfo($json_file, PATHINFO_FILENAME) . '-';

				foreach($data->tests as $test)
				{
					$this->tests[$name_prefix . $test->name] = $test;
				}
			}
		}

		return (count($this->tests) > 0);
	}

	protected function runTest(stdClass $test, &$info)
	{
		$info = 'Running test "' . $test->name . '" (' . $test->desc . ")...\n\n";

		$parser = new MustacheParser($test->template);

		if(isset($test->partials))
		{
			$parser->addPartials((array)$test->partials);
		}

		try
		{
			$parser->parse();
		}
		catch(MustacheParserException $ex)
		{
			$info .= '[PARSER EXCEPTION] ' . $ex->getMessage();
			return false;
		}

		$codegen = new MustachePHPCodeGen($parser);
		$code = $codegen->generate('view');

		$test_scope = function($view) use ($code)
		{
			eval('?>' . $code . '<?php ');
		};

		ob_start();
		$test_scope($test->data);
		$output = ob_get_clean();

		$pass = (strcmp($output, $test->expected) == 0);

		if($pass)
		{
			$info .= 'TEST PASSED!';
		}
		else
		{
			$info .= "TEST NOT PASSED:\n>output>\n" . $output . "\n<<>expected>\n" . $test->expected . "\n<<\n\nGENERATED CODE:\n\n$code";
			$info .= "\n\nTEMPLATE:\n\n{$test->template}\n\nDATA:\n\n" . json_encode($test->data) . "\n";
		}

		return $pass;
	}

	public function runTests($fail_output_dir)
	{
		echo 'Running ' . count($this->tests) . " tests...\n\n";

		$tests_passed = 0;

		foreach($this->tests as $test_id => $test)
		{
			$result = $this->runTest($test, $info);

			if($result)
			{
				$tests_passed++;
				echo "[PASS] '$test_id'\n";
			}
			else
			{
				echo "[FAIL] '{$test_id}'\n";

				if(is_dir($fail_output_dir))
				{
					file_put_contents($fail_output_dir . '/' . preg_replace('~[^a-zA-Z0-9 _.-]+~', '', $test_id) . '.txt', $info);
				}
			}
		}

		echo "Passed $tests_passed/" . count($this->tests) . " tests!\n";
	}
}

require_once dirname(__FILE__) . '/../lib/MustachePhpCodeGen.php';
require_once dirname(__FILE__) . '/../lib/MustacheRuntime.php';

$tests = new CompilingMustacheSpecsTests();

if($tests->loadTests(dirname(__FILE__) . '/specs'))
{
	$tests->runTests(dirname(__FILE__) . '/fail-output');
}
else
{
	echo '[ERROR] Unable to load specs test files.';
}
