<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

return array(
    'reviews' => array(
        'patterns' => array(
            'octothorpe'      => array(     // #review or #review-1234 with surrounding whitespace/eol
                'regex'  => '/(?P<pre>(?:\s|^)\(?)'
                          . '\#(?P<keyword>review)(?:-(?P<id>[0-9]+))?'
                          . '(?P<post>[.,!?:;)]*(?=\s|$))/i',
                'spec'   => '%pre%#%keyword%-%id%%post%',
                'insert' => "%description%\n\n#review-%id%",
                'strip'  => '/^\s*\#review(-[0-9]+)?(\s+|$)|(\s+|^)\#review(-[0-9]+)?\s*$/i'
            ),
            'leading-square'  => array(     // [review] or [review-1234] at start
                'regex'  => '/^(?P<pre>\s*)\[(?P<keyword>review)(?:-(?P<id>[0-9]+))?\](?P<post>\s*)/i',
                'spec'   => '%pre%[%keyword%-%id%]%post%'
            ),
            'trailing-square' => array(     // [review] or [review-1234] at end
                'regex'  => '/(?P<pre>\s*)\[(?P<keyword>review)(?:-(?P<id>[0-9]+))?\](?P<post>\s*)?$/i',
                'spec'   => '%pre%[%keyword%-%id%]%post%'
            )
        ),
        'disable_commit'       => false,
        'disable_self_approve' => false, // whether authors can approve their own reviews
        'commit_credit_author' => true,
        'commit_timeout'       => 1800,  // default: 30 minutes (must be in seconds)
        'unapprove_modified'   => true,  // whether approved reviews with modified files can be automatically unapproved
        'ignored_users'        => array()
    ),
    'security' => array(
        'login_exempt'  => array('review-tests', 'review-deploy')
    ),
    'router' => array(
        'routes' => array(
            'review' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review[/v:version][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'review',
                        'version'    => null
                    ),
                ),
            ),
            'review-version-delete' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/v:version/delete[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'deleteVersion',
                        'version'    => null
                    ),
                ),
            ),
            'review-reviewer' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/reviewers/:user[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'reviewer',
                        'user'       => null
                    ),
                ),
            ),
            'review-reviewers' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/reviewers[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'reviewers'
                    ),
                ),
            ),
            'review-vote' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/vote/:vote[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'vote',
                        'vote'       => null
                    ),
                ),
            ),
            'review-tests' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/tests/:status[/:token][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'testStatus',
                        'status'     => null,
                        'token'      => null
                    ),
                ),
            ),
            'review-deploy' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/deploy/:status[/:token][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'deployStatus',
                        'status'     => null,
                        'token'      => null
                    ),
                ),
            ),
            'review-transition' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/:review/transition[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'transition'
                    ),
                ),
            ),
            'reviews' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s][/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'index'
                    ),
                ),
            ),
            'add-review' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/review[s]/add[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'add'
                    ),
                ),
            ),
            'review-file' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/reviews?/(?P<review>[0-9]+)/v(?P<version>[0-9,]+)/files?(/(?P<file>.*))?',
                    'spec'     => '/reviews/%review%/v%version%/files/%file%',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'fileInfo',
                        'review'     => null,
                        'version'    => null,
                        'file'       => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Reviews\Controller\Index' => 'Reviews\Controller\IndexController'
        ),
    ),
    'service_manager' => array(
        'factories' => array(
            'review_keywords'   => function ($services) {
                $config = $services->get('config') + array('reviews' => array());
                $config = $config['reviews'] + array('patterns' => array());
                return new \Reviews\Filter\Keywords($config['patterns']);
            }
        )
    ),
    'view_manager' => array(
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'reviews'          => 'Reviews\View\Helper\Reviews',
            'reviewKeywords'   => 'Reviews\View\Helper\Keywords',
            'reviewersChanges' => 'Reviews\View\Helper\ReviewersChanges'
        ),
    ),
);
