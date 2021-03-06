This is a new mustache implementation in and for PHP.
mustache is a logic-less template language. You can find out more about it at:
http://mustache.github.com/

php-mustache consists of the following components:

- a mustache tokenizer and parser
- a mustache interpreter
- a mustache PHP code generator, dubbed "compiling mustache".
- a mustache JavaScript code generator, written in PHP

It aims to be compliant with the official mustache specs, as found on
https://github.com/mustache/spec

php-mustache is sufficiently mature and robust, however these are some things that are not currently implemented:

- lambdas [ no plans to add them currently ]
- calling methods on data objects (only properties are being read) [ should be an easy patch ]
- there are some failing tests [ all related to whitespace handling only! ]

It is strongly recommended to use UTF-8 encoded templates and data only.

## Examples

### mustache interpreted at runtime

```php
<?php
require_once 'lib/MustacheInterpreter.php';
$parser = new MustacheParser('Hello {{name}}!');
$parser->parse();
$mi = new MustacheInterpreter($parser);
$data = (object)array('name' => 'John Wayne');
echo $mi->run($data);
```

### mustache 'compiled' to PHP code

```php
<?php
require_once 'lib/MustachePhpCodeGen.php';
$parser = new MustacheParser('Hello {{name}}!');
$parser->parse();
$codegen = new MustachePHPCodeGen($parser);
// view is the name of the variable that the code expects to find its data
// in when run:
$code = $codegen->generate('view');
// you probably want to save this to your cached .tpl.php files or the like instead of echoing it:
echo $code . "\n";
```

### mustache 'compiled' to JavaScript code

```php
<?php
require_once 'lib/MustacheJavaScriptCodeGen.php';
$parser = new MustacheParser('Hello {{name}}!');
$parser->parse();
$codegen = new MustacheJavaScriptCodeGen($parser);
$code = $codegen->generate();
// you probably want to save this to a .js file or the like instead of echoing it:
echo $code . "\n";
// make sure to include the library code returned by
// MustacheJavaScriptCodeGen::getRuntimeCode()
// then just invoke the function(data){...} as returned by generate.
// Pass along the data object/array variable and receive the
// evaluated template+data results in return.
```

## MustacheParser public API

```php
<?php
/**
 * Mustache whitespace handling: Don't spend extra CPU cycles on trying to be 100% conforming to the specs.
 **/
define('MUSTACHE_WHITESPACE_LAZY', 1);
/**
 * Mustache whitespace handling: Try to be 100% conforming to the specs.
 **/
define('MUSTACHE_WHITESPACE_STRICT', 2);
/**
 * Mustache whitespace handling: Compact output, compact all superflous whitespace.
 **/
define('MUSTACHE_WHITESPACE_STRIP', 4);

/**
 * @param string $template
 * @param int $whitespace_mode
 **/
public function __construct($template, $whitespace_mode = MUSTACHE_WHITESPACE_LAZY);

/**
 * @return int
 **/
public function getWhitespaceMode();

/**
 * Adds a partial with name $key and template contents $tpl.
 * @param string $key
 * @param string $tpl
 **/
public function addPartial($key, $tpl);

/**
 * Adds multiple partials.
 * @see addPartial
 * @param array|object $partials
 **/
public function addPartials($partials);

/**
 * Adds a callback that will be queried for unknown partials that occur during parsing.
 * The signature of the callback is: <code>string pcb($partial_name)</code>
 * @param callable $callback
 **/
public function addPartialsCallback($callback);

/**
 * Empties the list of added partials and callbacks.
 **/
public function clearPartials();

/**
 * @throw Exception
 **/
public function parse();
```

## MustachePhpCodeGen public API

```php
<?php
/**
 * @param MustacheParser $parser Parser with the syntax tree.
 **/
public function __construct(MustacheParser $parser);

/**
 * Adjusts the flags for htmlspecialchars() calls in the generated code.
 * Only call this if you need to modify PHP's default flags!
 * @param int flags
 * @return void
 **/
public function setHtmlspecialcharsFlags($flags);

/**
 * Does the magic, i.e. turns the given parser tree into PHP code that
 * processes the data from $view_var_name according to the template.
 * @param string $view_var_name
 * @return string Returns false if there's no parser tree or no data variable.
 **/
public function generate($view_var_name);
```

## MustacheJavaScriptCodeGen public API

```php
<?php
/**
 * Note 1: for successful JS code generation, the template must be provided in UTF-8 encoding!
 * Note 2: while $compact_literals reduces the output code size, it must not be used when deploying
 * the generated code inside an HTML document as e.g. </script> is no longer being escaped. Do
 * not use if you don't fully understand the implications.
 * @param MustacheParser $parser Parser with the syntax tree.
 * @param bool $compact_literals
 **/
public function __construct(MustacheParser $parser, $compact_literals = false);

/**
 * Returns the runtime library JS code that is required when executing the
 * function(){...} code returned by generate. Feel free to minimize the
 * returned JS blob before deploying.
 * @see generate
 * @return string
 **/
public static function getRuntimeCode();

/**
 * Returns JavaScript code that yields equal results as the provided template.
 * Its structure looks like "function(data){...}" where data is the data variable
 * that is to be used while executing the template. The returned code can only
 * run successfully if the library provided by getRuntimeCode() is present.
 * @see getRuntimeCode
 * @return string
 **/
public function generate();
```

You can have a look at the [test suite invocation](https://github.com/KiNgMaR/php-mustache/blob/master/test/MustacheSpecsTests.php) for another example.
