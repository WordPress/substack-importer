#!/bin/bash
jq '. + { "autoload-dev": {
		"files": [
			"vendor/wp-phpunit/wp-phpunit/includes/phpunit7/MockObject/Builder/NamespaceMatch.php",
			"vendor/wp-phpunit/wp-phpunit/includes/phpunit7/MockObject/Builder/ParametersMatch.php",
			"vendor/wp-phpunit/wp-phpunit/includes/phpunit7/MockObject/InvocationMocker.php",
			"vendor/wp-phpunit/wp-phpunit/includes/phpunit7/MockObject/MockMethod.php"
		],
		"exclude-from-classmap": [
			"vendor/phpunit/phpunit/src/Framework/MockObject/Builder/NamespaceMatch.php",
			"vendor/phpunit/phpunit/src/Framework/MockObject/Builder/ParametersMatch.php",
			"vendor/phpunit/phpunit/src/Framework/MockObject/InvocationMocker.php",
			"vendor/phpunit/phpunit/src/Framework/MockObject/MockMethod.php"
		]
	}}' < composer.json > composer.json.tmp && mv composer.json.tmp composer.json

