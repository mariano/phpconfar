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
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;
use Symfony\Component\Validator\Constraints;
use Repository\AttendeeRepository;

$config = config();
if (empty($config['db']) || empty($config['urls']) || empty($config['users'])) {
	throw new Exception('Missing configuration');
}

$app = new Silex\Application();
$app->register(new TwigServiceProvider(), [
    'twig.path' => __DIR__.'/views',
    'twig.options' => ['cache' => __DIR__.'/../cache'],
]);
$app->register(new DoctrineServiceProvider());
$app->register(new FormServiceProvider());
$app->register(new SessionServiceProvider());
$app->register(new TranslationServiceProvider());
$app->register(new ValidatorServiceProvider());
$app->register(new SecurityServiceProvider(), [
	'security.firewalls' => [
		'login' => [
			'pattern' => '^/(login)?$'
		],
		'secured' => [
			'users' => function(Application $app) use($config) {
				$securityUsers = [];
				foreach($config['users'] as $role => $users) {
					foreach($users as $userName => $password) {
						$user = new User($userName, $password);
						$encoder = $app['security.encoder_factory']->getEncoder($user);
						$role = 'ROLE_' . strtoupper($role);
						$securityUsers[$userName] = [
							'roles' => [$role],
							'password' => $encoder->encodePassword($password, $user->getSalt())
						];
					}
				}
				return new InMemoryUserProvider($securityUsers);
			},
			'form' => [
				'login_path' => '/login',
				'check_path' => '/login_check',
				'failure_path' => '/login',
				'default_target_path' => '/registration',
				'always_use_default_target_path' => true
			],
			'logout' => [
				'logout_path' => '/logout',
				'target' => '/',
				'invalidate_session' => true
			]
		]
	],
	'security.access_rules' => [
		['^/import$', 'ROLE_ADMIN'],
		['^/.*$', 'ROLE_MANAGER'],
		['^/login$', 'IS_AUTHENTICATED_ANONYMOUSLY'],
		['^/$', 'IS_AUTHENTICATED_ANONYMOUSLY']
	],
	'security.role_hierarchy' => [
		'ROLE_ADMIN' => ['ROLE_MANAGER']
	]
]);

$app['db.options'] = [
    'driver'   => $config['db']['driver'],
    'dbname'   => $config['db']['dbname'],
    'host'     => $config['db']['host'],
    'user'     => $config['db']['user'],
    'password' => $config['db']['password'],
];

$app->before(function(Request $request) use ($app) {
    $app['db.attendee'] = $app->share(function($app) {
        return new AttendeeRepository($app['db']);
    });

	$flash = $app['session']->get('flash');
	if (!empty($flash)) {
		$app['session']->set('flash', null);
		$app['twig']->addGlobal('flash', $flash);
	}

	if ($app['security']->getToken()) {
		$app['twig']->addGlobal('roles', $app['security']->getToken()->getUser()->getRoles());
	}
});

$app->get('/', function() use($app) {
    return $app['twig']->render('index.html.twig', ['section' => 'home']);
});

$app->match('/login', function(Request $request) use ($app) {
	return $app['twig']->render('login.html.twig', [
		'error'         => $app['security.last_error']($request),
		'last_username' => $app['session']->get('_security.last_username'),
		'section' => 'login'
	]);
});

$app->post('/checkin', function(Request $request) use($app) {
	if (
		!$request->request->get('code') ||
		!$request->request->get('source') ||
		!$request->request->get('day') ||
		!in_array($request->request->get('day'), ['1', '2'])
	) {
		$app->abort(400, "Missing required data");
	}

	$record = $app['db.attendee']->findOneByCode($request->request->get('code'), $request->request->get('source'));
	if (!$record) {
		$app->abort(404, "Attendee does not exist.");
	}

	$field = 'checkin_day' . $request->request->get('day');
	$app['db.attendee']->update([
		$field => empty($record[$field]) ? date('Y-m-d H:i:s') : null
	], ['id' => $record['id']]);

	$app['session']->set('flash', [
		'type' => 'success',
		'title' => 'Record updated',
		'message' => 'Checkin status updated!'
	]);

	return $app->redirect('/registration');
});

$app->match('/edit/{id}', function($id, Request $request) use($app) {
	$record = $app['db.attendee']->find($id);
	if (!$record) {
		$app->abort(404, "Attendee #$id does not exist.");
	}

	$form = $app['form.factory']->createBuilder('form', $record)
		->add('code', 'text', [
			'constraints' => [new Constraints\NotBlank(), new Constraints\Length(['min' => 2])],
			'label' => 'Code:',
			'disabled' => true
		])
		->add('source', 'choice',[
			'choices' => ['eventioz' => 'Eventioz', 'evenbrite' => 'Evenbrite'],
			'required' => false,
			'empty_value' => '-- Pick ticket source --',
			'empty_data' => null,
			'constraints' => [new Constraints\NotBlank(), new Constraints\Choice(['eventioz', 'evenbrite'])],
		])
		->add('email', 'text', [
			'constraints' => [new Constraints\Email()],
			'label' => 'First name:',
		])
		->add('first_name', 'text', [
			'constraints' => [new Constraints\NotBlank()],
			'label' => 'First name:',
		])
		->add('last_name', 'text', [
			'constraints' => [new Constraints\NotBlank()],
			'label' => 'First name:',
		])
		->add('role', 'choice', [
			'choices' => ['attendee' => 'Attendee', 'speaker' => 'Speaker', 'support' => 'Support', 'organizer' => 'Organizer'],
			'required' => false,
			'empty_value' => '-- Pick a role --',
			'empty_data' => null,
			'constraints' => [new Constraints\NotBlank(), new Constraints\Choice(['attendee', 'speaker', 'support', 'organizer'])],
		])
		->getForm();

	if ('POST' === $request->getMethod()) {
		$form->bind($request);
		if ($form->isValid()) {
			$data = $form->getData();
			$app['db.attendee']->update(array_intersect_key($data, ['source'=>null, 'email'=>null, 'first_name'=>null, 'last_name'=>null, 'role'=>null]), compact('id'));
			$app['session']->set('flash', [
				'type' => 'success',
				'title' => 'Record updated',
				'message' => 'You have successfully updated the record'
			]);
			return $app->redirect('/registration');
		} else {
			$app['session']->set('flash', [
				'type' => 'error',
				'title' => 'Validation failed',
				'message' => 'Please correct the errors marked below'
			]);
			$app['twig']->addGlobal('flash', $app['session']->get('flash'));
		}
	}

    return $app['twig']->render('edit.html.twig', [
		'section' => 'registration',
		'form' => $form->createView()
	]);
});

$app->match('/import', function(Request $request) use($app, $config) {
	if ('POST' === $request->getMethod()) {
		$result = $app['db.attendee']->import($config);
	}
    return $app['twig']->render('import.html.twig', ['section' => 'import'] + (!empty($result) ? $result : []));
});

$app->match('/registration/{all}', function($all, Request $request) use($app) {
	$form = $app['form.factory']->createBuilder('form')
		->add('code', 'search', [
			'constraints' => [new Constraints\NotBlank(), new Constraints\Length(['min' => 2])]
		])
		->getForm();

	if ('POST' === $request->getMethod()) {
		$form->bind($request);
		if ($form->isValid()) {
			$data = $form->getData();
			$tickets = $app['db.attendee']->findTicket($data['code']);
		}
	} else if (!empty($all)) {
		$tickets = $app['db.attendee']->tickets();
	}

    return $app['twig']->render('registration.html.twig', [
		'section' => 'registration',
		'form' => $form->createView()
	] + (!empty($tickets) ? compact('tickets') : []));
})->value('all', null);

$app->match('/raffle', function(Request $request) use($app) {
	if (in_array('application/json', $request->getAcceptableContentTypes())) {
		return $app->json($app['db.attendee']->raffle());
	}
	$records = $app['db.attendee']->findAll(['first_name', 'last_name'], 500);
	foreach($records as $i => $record) {
		$records[$i] = trim(implode(' ', $record));
	}
    return $app['twig']->render('raffle.html.twig', [
		'section' => 'raffle',
		'names' => array_filter(array_unique($records))
	]);
});

return $app;