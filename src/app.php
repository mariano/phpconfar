<?php
/**

CREATE TABLE `attendees`(
	`id` INT NOT NULL AUTO_INCREMENT,
	`code` VARCHAR(255) NOT NULL,
	`source` ENUM('eventioz', 'evenbrite') NOT NULL,
	`email` VARCHAR(255) NOT NULL,
	`first_name` VARCHAR(255) default NULL,
	`last_name` VARCHAR(255) default NULL,
	`checkin_day1` DATETIME default NULL,
	`checkin_day2` DATETIME default NULL,
	PRIMARY KEY(`id`),
	UNIQUE KEY `attendees__code__source`(`source`, `code`)
);

*/

require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\FormServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\ValidatorServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints;
use Repository\AttendeeRepository;

$env = getenv('APPLICATION_ENV') ? getenv('APPLICATION_ENV') : 'production';
$settings = parse_ini_file(__DIR__.'/config.ini', TRUE);
$config = array();
foreach($settings[$env] as $key => $value) {
	if (strpos($key, '.') !== false) {
		list($key, $innerKey) = explode('.', $key);
		$config[$key][$innerKey] = $value;
	} else {
		$config[$key] = $value;
	}
}

if (empty($config['db']) || empty($config['urls']) || empty($config['users'])) {
	throw new Exception('Missing configuration');
}

$app = new Silex\Application();
$app['debug'] = true;

$app->register(new TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
    'twig.options' => array('cache' => __DIR__.'/../cache'),
));
$app->register(new DoctrineServiceProvider());
$app->register(new FormServiceProvider());
$app->register(new SecurityServiceProvider());
$app->register(new SessionServiceProvider());
$app->register(new TranslationServiceProvider());
$app->register(new ValidatorServiceProvider());

$securityUsers = array();
foreach($config['users'] as $userName => $password) {
	$user = new \Symfony\Component\Security\Core\User\User($userName, $password);
	$encoder = $app['security.encoder_factory']->getEncoder($user);
	$securityUsers[$userName] = array('ROLE_MANAGE', $encoder->encodePassword($password, $user->getSalt()));
}

$app['security.firewalls'] = array(
	'default' => array(
		'http' => true,
		'users' => $securityUsers
	)
);

$app['db.options'] = array(
    'driver'   => $config['db']['driver'],
    'dbname'   => $config['db']['dbname'],
    'host'     => $config['db']['host'],
    'user'     => $config['db']['user'],
    'password' => $config['db']['password'],
);

$app->before(function() use ($app) {
    $app['db.attendee'] = $app->share(function($app) {
        return new AttendeeRepository($app['db']);
    });
	$flash = $app['session']->get('flash');
	if (!empty($flash)) {
		$app['session']->set('flash', null);
		$app['twig']->addGlobal('flash', $flash);
	}
});

$app->get('/', function() use($app) {
    return $app['twig']->render('index.html.twig', array('section' => 'home'));
});

$app->post('/checkin', function(Request $request) use($app) {
	if (
		!$request->request->get('code') ||
		!$request->request->get('source') ||
		!$request->request->get('day') ||
		!in_array($request->request->get('day'), array('1', '2'))
	) {
		$app->abort(503, "Missing required data");
	}

	$record = $app['db.attendee']->findOneByCode($request->request->get('code'), $request->request->get('source'));
	if (!$record) {
		$app->abort(404, "Attendee does not exist.");
	}

	$field = 'checkin_day' . $request->request->get('day');
	$app['db.attendee']->update(array(
		$field => empty($record[$field]) ? date('Y-m-d H:i:s') : null
	), array('id' => $record['id']));

	$app['session']->set('flash', array(
		'type' => 'success',
		'title' => 'Record updated',
		'message' => 'Checkin status updated!'
	));

	return $app->redirect('/registration');
});

$app->match('/edit/{id}', function($id, Request $request) use($app) {
	$record = $app['db.attendee']->find($id);
	if (!$record) {
		$app->abort(404, "Attendee #$id does not exist.");
	}

	$form = $app['form.factory']->createBuilder('form', $record)
		->add('code', 'text', array(
			'constraints' => array(new Constraints\NotBlank(), new Constraints\Length(array('min' => 2))),
			'label' => 'Code:',
			'disabled' => true
		))
		->add('source', 'choice', array(
			'choices' => array('eventioz' => 'Eventioz', 'evenbrite' => 'Evenbrite'),
			'required' => false,
			'empty_value' => '-- Figure it out yourself --',
			'empty_data' => null,
			'constraints' => array(new Constraints\NotBlank(), new Constraints\Choice(array('eventioz', 'evenbrite'))),
		))
		->add('email', 'text', array(
			'constraints' => array(new Constraints\Email()),
			'label' => 'First name:',
		))
		->add('first_name', 'text', array(
			'constraints' => array(new Constraints\NotBlank()),
			'label' => 'First name:',
		))
		->add('last_name', 'text', array(
			'constraints' => array(new Constraints\NotBlank()),
			'label' => 'First name:',
		))
		->getForm();

	if ('POST' === $request->getMethod()) {
		$form->bind($request);
		if ($form->isValid()) {
			$data = $form->getData();
			$app['db.attendee']->update(array_intersect_key($data, array('source'=>null, 'email'=>null, 'first_name'=>null, 'last_name'=>null)), compact('id'));
			$app['session']->set('flash', array(
				'type' => 'success',
				'title' => 'Record updated',
				'message' => 'You have successfully updated the record'
			));
		}
	}

    return $app['twig']->render('edit.html.twig', array(
		'section' => 'registration',
		'form' => $form->createView()
	));
});

$app->match('/import', function(Request $request) use($app, $config) {
	if ('POST' === $request->getMethod()) {
		$result = $app['db.attendee']->import($config);
	}
    return $app['twig']->render('import.html.twig', array('section' => 'import') + (!empty($result) ? $result : array()));
});

$app->match('/registration', function(Request $request) use($app) {
	$form = $app['form.factory']->createBuilder('form')
		->add('code', 'text', array(
			'constraints' => array(new Constraints\NotBlank(), new Constraints\Length(array('min' => 2))),
			'label' => 'Code / Email / Name:',
			'attr' => array(
				'class' => 'input-xxlarge'
			)
		))
		->getForm();

	if ('POST' === $request->getMethod()) {
		$form->bind($request);
		if ($form->isValid()) {
			$data = $form->getData();
			$tickets = $app['db.attendee']->findTicket($data['code']);
		}
	}

    return $app['twig']->render('registration.html.twig', array(
		'section' => 'registration',
		'form' => $form->createView()
	) + (!empty($tickets) ? compact('tickets') : array()));
});

return $app;