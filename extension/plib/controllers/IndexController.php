<?php
class IndexController extends pm_Controller_Action
{
    protected $_accessLevel = ['admin', 'reseller', 'client'];
    private $csrf;

    public function init()
    {
        parent::init();
        $ns = new Zend_Session_Namespace('help4_disk_usage');
        if (!$ns->csrf) {
            $ns->csrf = bin2hex(random_bytes(32));
        }
        $this->csrf = $ns->csrf;
        $this->view->csrf = $this->csrf;
        $this->view->pageTitle = Modules_Help4DiskUsage_Store::policy()['title'];
        $this->view->headLink()->appendStylesheet(pm_Context::getBaseUrl() . 'css/audit.css');
        $this->view->headScript()->appendFile(pm_Context::getBaseUrl() . 'js/audit.js');
        $this->getResponse()->setHeader('Cache-Control', 'no-store', true);
        $this->getResponse()->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->getResponse()->setHeader('Referrer-Policy', 'same-origin', true);
    }

    private function post()
    {
        $token = $this->getRequest()->getPost('csrf');
        if (!$this->getRequest()->isPost() || !is_string($token) || !hash_equals($this->csrf, $token)) {
            throw new RuntimeException('Invalid request; reload this page');
        }
    }

    public function indexAction()
    {
        $domains = Modules_Help4DiskUsage_Access::domains();
        $this->view->domains = $domains;
        $this->view->admin = Modules_Help4DiskUsage_Access::admin();
        $this->view->report = null;
        $this->view->domain = null;
        $this->view->notice = $this->getParam('queued') === '1' ? 'Scan queued. Your previous report remains available.' : '';
        if (!$domains) {
            return;
        }
        $domain = Modules_Help4DiskUsage_Access::domain($this->getParam('domain', $domains[0]->getId()));
        $this->view->domain = $domain;
        $report = Modules_Help4DiskUsage_Store::report($domain);
        $this->view->report = $report;
        $policy = Modules_Help4DiskUsage_Store::effective($domain);
        $this->view->ttl = $policy['ttl'];
        $this->view->pending = isset(Modules_Help4DiskUsage_Store::read('state')['pending'][$domain->getId()]);
        $this->view->search = substr((string)$this->getParam('search', ''), 0, 128);
        $this->view->sort = (string)$this->getParam('sort', $this->getParam('section') === 'entry_trees' ? 'entries' : 'bytes');
        $this->view->result = Modules_Help4DiskUsage_Report::rows($report ?? [], $this->getParam('section', 'largest_files'),
            $this->view->search, $this->view->sort, $this->getParam('page', 1));
    }

    public function refreshAction()
    {
        $this->post();
        $domain = Modules_Help4DiskUsage_Access::domain($this->getRequest()->getPost('domain'));
        try {
            $token = Modules_Help4DiskUsage_Store::reserve($domain, pm_Session::getClient(), Modules_Help4DiskUsage_Access::admin());
            $task = new Modules_Help4DiskUsage_Task_Scan();
            $task->setParams(['domain' => $domain->getId(), 'token' => $token]);
            try {
                (new pm_LongTask_Manager())->start($task, $domain);
            } catch (Throwable $e) {
                Modules_Help4DiskUsage_Store::finish($domain->getId(), $token);
                throw new RuntimeException('Background scan unavailable; contact your host');
            }
            $this->_status->addMessage('info', 'Scan queued');
        } catch (RuntimeException $e) {
            $this->_status->addMessage('warning', $e->getMessage());
        }
        $this->_redirect(pm_Context::getActionUrl('index', 'index') . '?domain=' . $domain->getId(), ['code' => 303]);
    }

    public function exportAction()
    {
        $domain = Modules_Help4DiskUsage_Access::domain($this->getParam('domain'));
        $report = Modules_Help4DiskUsage_Store::report($domain);
        if (!$report) {
            throw new RuntimeException('Report unavailable');
        }
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();
        $format = $this->getParam('format', 'json');
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, ['section', 'path', 'kind', 'bytes', 'entries', 'hint'], ',', '"', '');
            foreach (['largest_files', 'stale_files', 'largest_trees', 'entry_trees'] as $section) {
                foreach ($report[$section] ?? [] as $row) {
                    fputcsv($stream, array_map(['Modules_Help4DiskUsage_Report', 'csvCell'],
                        [$section, $row['path'], $row['kind'], $row['bytes'], $row['entries'] ?? '', $row['hint']]), ',', '"', '');
                }
            }
            fputcsv($stream, ['Built by', 'Help4 Network', 'https://help4network.com'], ',', '"', '');
            rewind($stream);
            $body = stream_get_contents($stream);
            fclose($stream);
            $type = 'text/csv; charset=UTF-8';
        } else {
            $format = 'json';
            $body = json_encode(Modules_Help4DiskUsage_Report::publicReport($report), JSON_THROW_ON_ERROR);
            $type = 'application/json; charset=UTF-8';
        }
        $this->getResponse()->setHeader('Content-Type', $type, true)
            ->setHeader('Content-Disposition', 'attachment; filename="disk-audit.' . $format . '"', true)->setBody($body);
    }

    public function openAction()
    {
        $domain = Modules_Help4DiskUsage_Access::domain($this->getParam('domain'));
        $report = Modules_Help4DiskUsage_Store::report($domain);
        if (!$report) {
            throw new RuntimeException('Report unavailable');
        }
        $url = Modules_Help4DiskUsage_Report::fileManagerUrl($domain, $report, $this->getParam('path'), $this->getParam('kind'));
        $this->_redirect($url);
    }

    public function settingsAction()
    {
        if (!Modules_Help4DiskUsage_Access::admin()) {
            throw new RuntimeException('Administrator access required');
        }
        $this->view->policy = Modules_Help4DiskUsage_Store::policy();
        if ($this->getRequest()->isPost()) {
            $this->post();
            try {
                $data = $this->getRequest()->getPost();
                $data['overrides'] = json_decode($data['overrides_json'] ?? '{}', true, 16, JSON_THROW_ON_ERROR);
                $policy = Modules_Help4DiskUsage_Store::validatePolicy($data);
                Modules_Help4DiskUsage_Store::locked(function () use ($policy) {
                    Modules_Help4DiskUsage_Store::write('policy', $policy);
                });
                $this->_status->addMessage('info', 'Settings saved');
                $this->_redirect(pm_Context::getActionUrl('index', 'settings'), ['code' => 303]);
            } catch (Throwable $e) {
                $this->_status->addMessage('warning', 'Settings not saved. Check the limits, Python path and subscription overrides.');
            }
        }
    }
}
