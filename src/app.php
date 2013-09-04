<?php
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\FormServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\ValidatorServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Validator\Constraints;
use Repository\AttendeeRepository;

$config = config();
if (empty($config['db']) || empty($config['urls']) || empty($config['users'])) {
	throw new Exception('Missing configuration');
}

$app = new Silex\Application();
$app['debug'] = true;

$app->register(new TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
    //'twig.options' => array('cache' => __DIR__.'/../cache'),
));
$app->register(new DoctrineServiceProvider());
$app->register(new FormServiceProvider());
$app->register(new SecurityServiceProvider());
$app->register(new SessionServiceProvider());
$app->register(new TranslationServiceProvider());
$app->register(new ValidatorServiceProvider());

$securityUsers = array();
foreach($config['users'] as $role => $users) {
	foreach($users as $userName => $password) {
		$user = new User($userName, $password);
		$encoder = $app['security.encoder_factory']->getEncoder($user);
		$securityUsers[$userName] = array($role, $encoder->encodePassword($password, $user->getSalt()));
	}
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
	if (count(array_intersect(array('manager', 'admin'), $app['security']->getToken()->getUser()->getRoles())) === 0) {
		$app->abort(403, "You do not have the required credentials");
	}

    $app['db.attendee'] = $app->share(function($app) {
        return new AttendeeRepository($app['db']);
    });

	$flash = $app['session']->get('flash');
	if (!empty($flash)) {
		$app['session']->set('flash', null);
		$app['twig']->addGlobal('flash', $flash);
	}

	$app['twig']->addGlobal('roles', $app['security']->getToken()->getUser()->getRoles());
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
		$app->abort(400, "Missing required data");
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
			'empty_value' => '-- Pick ticket source --',
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
		->add('role', 'choice', array(
			'choices' => array('attendee' => 'Attendee', 'speaker' => 'Speaker', 'support' => 'Support', 'organizer' => 'Organizer'),
			'required' => false,
			'empty_value' => '-- Pick a role --',
			'empty_data' => null,
			'constraints' => array(new Constraints\NotBlank(), new Constraints\Choice(array('attendee', 'speaker', 'support', 'organizer'))),
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
		} else {
			$app['session']->set('flash', array(
				'type' => 'error',
				'title' => 'Validation failed',
				'message' => 'Please correct the errors marked below'
			));
		}
	}

    return $app['twig']->render('edit.html.twig', array(
		'section' => 'registration',
		'form' => $form->createView()
	));
});

$app->match('/import', function(Request $request) use($app, $config) {
	if (!in_array('admin', $app['security']->getToken()->getUser()->getRoles())) {
		$app->abort(403, "You do not have the required credentials");
	}
	if ('POST' === $request->getMethod()) {
		$result = $app['db.attendee']->import($config);
	}
    return $app['twig']->render('import.html.twig', array('section' => 'import') + (!empty($result) ? $result : array()));
});

$app->match('/registration', function(Request $request) use($app) {
	$form = $app['form.factory']->createBuilder('form')
		->add('code', 'search', array(
			'constraints' => array(new Constraints\NotBlank(), new Constraints\Length(array('min' => 2)))
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

$app->match('/raffle', function(Request $request) use($app) {
	if (in_array('application/json', $request->getAcceptableContentTypes())) {
		$result = $app['db.attendee']->raffle();
		return new Response(json_encode($result), 200, array('Content-type' => 'application/json'));
	}
	$records = $app['db.attendee']->findAll(array('first_name', 'last_name'), 500);
	foreach($records as $i => $record) {
		$records[$i] = trim(implode(' ', $record));
	}
    return $app['twig']->render('raffle.html.twig', array(
		'section' => 'raffle',
		'names' => array_filter(array_unique($records))
	));
});

return $app;