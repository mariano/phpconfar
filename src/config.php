<?php
function config($env = null) {
	$environments = array('production', 'staging', 'development');
	if (empty($env)) {
		$env = getenv('APPLICATION_ENV') ? getenv('APPLICATION_ENV') : 'production';
	}
	if (!in_array($env, $environments)) {
		throw new InvalidArgumentException("Invalid environment {$env}");
	}

	$config = array();
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