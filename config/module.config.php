<?php declare(strict_types=1);

namespace Urify;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\UrifyForm::class => Service\Form\UrifyFormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'Urify\Controller\Admin\Index' => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    // TODO Remove these routes and use main admin/default.
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'urify' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/urify',
                            'defaults' => [
                                '__NAMESPACE__' => 'Urify\Controller\Admin',
                                '__ADMIN__' => true,
                                'controller' => 'Index',
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                    'default' => 'browse',
                                ],
                            ],
                            'id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:id[/:action]',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'urify' => [
                'label' => 'Urify values', // @translate
                'route' => 'admin/urify/default',
                'controller' => 'index',
                'action' => 'add',
                'class' => 'o-icon- fa-link',
                'pages' => [
                    [
                        'route' => 'admin/urify/default',
                        'visible' => false,
                    ],
                    [
                        'route' => 'admin/urify/id',
                        'visible' => false,
                    ],
                ],
            ],
        ],
        'Urify' => [
            [
                'label' => 'Prepare', // @translate
                'route' => 'admin/urify/default',
                'controller' => 'Index',
                'action' => 'add',
            ],
            [
                'label' => 'Browse', // @translate
                'route' => 'admin/urify/default',
                'controller' => 'Index',
                'action' => 'browse',
                'pages' => [
                    [
                        'route' => 'admin/urify',
                        'controller' => 'Index',
                        'visible' => false,
                    ],
                    [
                        'route' => 'admin/urify/id',
                        'controller' => 'Index',
                        'visible' => false,
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'urify' => [
    ],
];
