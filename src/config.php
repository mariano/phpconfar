<?php
function config($env = null) {
	$environments = ['production', 'staging', 'development'];
	if (empty($env)) {
		$env = 'production';
	}
	if (!in_array($env, $environments)) {
		throw new InvalidArgumentException("Invalid environment {$env}");
	}

	$config = [];
	foreach(parse_ini_file(__DIR__.'/config.ini', true) as $group => $settings) {
		if (in_array($group, $environments) && $group !== $env) {
			continue;
		}
		foreach($settings as $key => $value) {
			$innerKey = null;
			if (strpos($key, '.') !== false) {
				list($key, $innerKey) = explode('.', $key);
			}

			if ($group !== $env) {
				if (isset($innerKey)) {
					$config[$group][$key][$innerKey] = $value;
				} else {
					$config[$group][$key] = $value;
				}
			} else if (isset($innerKey)) {
				$config[$key][$innerKey] = $value;
			} else {
				$config[$key] = $value;
			}
		}
	}
	return $config;
}