<?php declare(strict_types=1);

namespace Urify\Controller\Admin;

use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\DataType\Manager as DataTypeManager;
use Omeka\Mvc\Exception\NotFoundException;
use Urify\Form\UrifyForm;
use Urify\Job\UrifyValues;
use Urify\Job\UrifyValuesApply;

class IndexController extends AbstractActionController
{
    /**
     * @var \\Omeka\DataType\Manager
     */
    protected $dataTypeManager;

    public function __construct(DataTypeManager $dataTypeManager)
    {
        $this->dataTypeManager = $dataTypeManager;
    }

    public function indexAction()
    {
        return $this->redirect()->toRoute('admin/urify/default', ['controller' => 'index', 'action' => 'add']);
    }

    public function addAction()
    {
        /** @var \Urify\Form\UrifyForm $form */
        $form = $this->getForm(UrifyForm::class);
        $form->init();

        $view = new ViewModel([
            'form' => $form,
            'dataTypeManager' => $this->dataTypeManager,
        ]);

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $view;
        }

        $params = $request->getPost();

        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $params = $form->getData();
        unset($params['csrf']);

        $properties = $params['properties'] ?? [];
        if (!$properties) {
            $this->messenger()->addError('No properties defined.'); // @translate
            return $view;
        }

        $modes = $params['modes'] ?? [];
        if (!$modes) {
            $this->messenger()->addError('No process defined.'); // @translate
            return $view;
        }
        if (!is_array($modes)) {
            $modes = [$modes];
        }

        $valueTypes = $params['value_types'] ?? [];
        if (!$valueTypes) {
            $this->messenger()->addError('No value types defined.'); // @translate
            return $view;
        }

        $dataType = $params['datatype'] ?? null;
        if (!$dataType) {
            $this->messenger()->addError('No destination data type defined.'); // @translate
            return $view;
        }

        $query = $params['query'] ?? [];
        if ($query) {
            if (is_string($query)) {
                $q = $query;
                parse_str($q, $query);
            }
            // Quick clean query.
            $arrayFilterRecursiveEmpty = null;
            $arrayFilterRecursiveEmpty = function (array &$array) use (&$arrayFilterRecursiveEmpty): array {
                foreach ($array as $key => $value) {
                    if (is_array($value) && $value) {
                        $array[$key] = $arrayFilterRecursiveEmpty($value);
                    }
                    if (in_array($array[$key], ['', null, []], true)) {
                        unset($array[$key]);
                    }
                }
                return $array;
            };
            $arrayFilterRecursiveEmpty($query);
        }

        $args = [
            'label' => $params['label'] ?? '',
            'query' => $query,
            'modes' => $modes,
            'properties' => $properties,
            'value_types' => $valueTypes,
            'datatype' => $dataType,
            'language' => empty($params['language']) ? null : $params['language'],
        ];

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        // Use synchronous dispatcher for quick testing purpose.
        $strategy = null;
        // $strategy = $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()->get(\Omeka\Job\DispatchStrategy\Synchronous::class);
        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(UrifyValues::class, $args, $strategy);
        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing search of {link_result}values to urify{link_end} in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_result' => sprintf('<a href="%s">', $urlPlugin->fromRoute('admin/urify/id', ['id' => $job->getId()])),
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        //  Reinit the list for a new job.
        $form = $this->getForm(UrifyForm::class);
        return new ViewModel([
            'form' => $form,
            'dataTypeManager' => $this->dataTypeManager,
        ]);
    }

    public function browseAction()
    {
        $this->browse()->setDefaults('jobs');

        $query = $this->params()->fromQuery();
        $query = ['class' => UrifyValues::class] + $query;

        $response = $this->api()->search('jobs', $query);
        $this->paginator($response->getTotalResults());

        $services = $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator();
        $config = $services->get('Config');
        $baseUrlFiles = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';

        return new ViewModel([
            'jobs' => $response->getContent(),
            'baseUrlFiles' => $baseUrlFiles,
            'dataTypeManager' => $this->dataTypeManager,
        ]);
    }

    public function showAction()
    {
        $id = $this->params('id');

        try {
            /** @var \Omeka\Api\Representation\JobRepresentation $job */
            $job = $this->api()->read('jobs', ['id' => $id, 'class' => UrifyValues::class])->getContent();
        } catch (\Exception $e) {
            throw new NotFoundException();
        }

        $services = $job->getServiceLocator();
        $config = $services->get('Config');
        $baseUrlFiles = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';

        $hasMapper = class_exists('Mapper\Module', false);
        if ($hasMapper) {
            $updateMode = $this->settings()->get('urify_update_mode', 'single');
            $updateMapper = $this->settings()->get('urify_update_mapper', '');
            $form = $this->getForm(\Urify\Form\UrifyMapperForm::class);
            $form->setData([
                'update_mode' => $updateMode,
                'update_mapper' => $updateMapper,
            ]);
        } else {
            $updateMode = null;
            $updateMapper = null;
            $form = null;
        }

        $results = $job->args()['results'] ?? [];
        return new ViewModel([
            'job' => $job,
            'results' => $results,
            'baseUrlFiles' => $baseUrlFiles,
            'form' => $form,
            'hasMapper' => $hasMapper,
            'updateMode' => $updateMode,
            'updateMapper' => $updateMapper,
        ]);
    }


    public function applyAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->jSend()->fail(null, $this->translate(
                'Not found', // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        $id = $this->params('id');
        try {
            /** @var \Omeka\Api\Representation\JobRepresentation $job */
            $job = $this->api()->read('jobs', ['id' => $id, 'class' => UrifyValues::class])->getContent();
        } catch (\Exception $e) {
            return $this->jSend()->fail(null, $this->translate(
                'Not found', // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        $jobArgs = $job->args();

        $params = $this->params()->fromPost();

        $property = $params['property'] ?? null;
        $property = $this->easyMeta()->propertyTerm($property);
        $value = trim((string) ($params['value'] ?? ''));
        $uri = $params['uri'] ?? null;
        $label = trim((string) ($params['label'] ?? ''));
        $dataType = $jobArgs['datatype'] ?? null;

        if (!strlen($value)
            || !$uri
            || !$property
            || !$dataType
            || !$this->dataTypeManager->has($dataType)
        ) {
            return $this->jSend()->fail(null, $this->translate(
                'Missing required parameters.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        $hasMapper = class_exists('Mapper\Module', false);

        if ($hasMapper) {
            $updateMode = ($params['update_mode'] ?? null) === 'multiple' ? 'multiple' : 'single';
            $updateMapper = empty($params['update_mapper']) ? null : $params['update_mapper'];
            if ($updateMode === 'multiple' && !$updateMapper) {
                return $this->jSend()->fail(null, $this->translate(
                    'A mapper is required when using mode "multiple".' // @translate
                ));
            }
        } else {
            $updateMode = 'single';
            $updateMapper = null;
        }

        $this->settings()->set('urify_update_mode', $updateMode);
        $this->settings()->set('urify_update_mapper', $updateMapper);

        // Api batchUpdate cannot be used, because it does not support to update
        // a single property value.
        // Standard omeka batch update may be used, but it runs multiple
        // sub-batch-update and is not optimized for this case.
        // So use a specific job.

        $query = $jobArgs['query'] ?? [];

        // Use the default omeka job.
        $args = [
            'value' => $value,
            'uri' => $uri,
            'label' => strlen($label) ? $label : null,
            'property' => $property,
            'datatype' => $dataType,
            'value_types' => $jobArgs['value_types'] ?? [],
            'query' => $query,
            'updateMode' => $updateMode,
            'updateMapper' => $updateMapper,
        ];

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        // Use synchronous dispatcher for quick testing purpose.
        $strategy = null;
        // $strategy = $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()->get(\Omeka\Job\DispatchStrategy\Synchronous::class);
        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(UrifyValuesApply::class, $args, $strategy);
        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing {link_result}urification{link_end} of values in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_result' => sprintf('<a href="%s">', $urlPlugin->fromRoute('admin/urify/id', ['id' => $id])),
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);

        return $this->jSend()->success(['args' => $args],$message);
    }
}
