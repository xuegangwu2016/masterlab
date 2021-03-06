<?php
/**
 * Created by PhpStorm.
 */

namespace main\app\ctrl\issue;

use main\app\classes\IssueFilterLogic;
use main\app\classes\IssueFavFilterLogic;
use main\app\classes\IssueLogic;
use main\app\classes\IssueTypeLogic;
use main\app\classes\RewriteUrl;
use \main\app\classes\UploadLogic;
use main\app\classes\UserAuth;
use main\app\classes\UserLogic;
use main\app\classes\WorkflowLogic;
use main\app\classes\ConfigLogic;
use main\app\classes\Settings;
use main\app\ctrl\BaseCtrl;
use main\app\ctrl\BaseUserCtrl;
use main\app\model\ActivityModel;
use main\app\model\agile\SprintModel;
use main\app\model\project\ProjectLabelModel;
use main\app\model\project\ProjectModel;
use main\app\model\project\ProjectVersionModel;
use main\app\model\project\ProjectModuleModel;
use main\app\model\issue\IssueFileAttachmentModel;
use main\app\model\issue\IssueFilterModel;
use main\app\model\issue\IssueResolveModel;
use main\app\model\issue\IssuePriorityModel;
use main\app\model\issue\IssueDescriptionTemplateModel;
use main\app\model\issue\IssueFollowModel;
use main\app\model\issue\IssueModel;
use main\app\model\issue\IssueLabelDataModel;
use main\app\model\issue\IssueFixVersionModel;
use main\app\model\issue\IssueTypeModel;
use main\app\model\issue\IssueStatusModel;
use main\app\model\issue\IssueUiModel;
use main\app\model\issue\IssueUiTabModel;
use main\app\model\issue\IssueRecycleModel;
use main\app\model\field\FieldTypeModel;
use main\app\model\field\FieldModel;
use main\app\model\user\UserModel;
use main\app\classes\PermissionLogic;
use main\app\classes\LogOperatingLogic;

/**
 * 事项
 */
class Main extends BaseUserCtrl
{

    /**
     * Main constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
        parent::addGVar('top_menu_active', 'issue');
    }

    /**
     * 事项列表页面
     * @throws \Exception
     */
    public function pageIndex()
    {
        $data = [];
        $data['title'] = '事项';
        $data['nav_links_active'] = 'issues';
        $data['sub_nav_active'] = 'all';
        $data['query_str'] = http_build_query($_GET);
        $data['search'] = isset($_GET['search']) ? $_GET['search'] : '';
        $data['sys_filter'] = isset($_GET['sys_filter']) ? $_GET['sys_filter'] : '';
        $data['active_id'] = isset($_GET['active_id']) ? $_GET['active_id'] : '';
        $data['sort_field'] = isset($_GET['sort_field']) ? $_GET['sort_field'] : '';
        $data['sort_by'] = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'desc';
        $data = RewriteUrl::setProjectData($data);
        $data['issue_main_url'] = ROOT_URL . 'issue/main';
        if (!empty($data['project_id'])) {
            $data['issue_main_url'] = ROOT_URL . substr($data['project_root_url'], 1) . '/issues';
        }
        if (isset($_GET['fav_filter'])) {
            $favFilterId = (int)$_GET['fav_filter'];
            $favFilterModel = new IssueFilterModel();
            $fav = $favFilterModel->getItemById($favFilterId);
            if (isset($fav['filter']) && !empty($fav['filter'])) {
                $fav['filter'] = str_replace([':', ' '], ['=', '&'], $fav['filter']);
                $fav['filter'] = str_replace(['经办人=@', '报告人=@'], ['经办人=', '报告人='], $fav['filter']);
                $filter = $fav['filter'] . '&active_id=' . $favFilterId;
                $issueUrl = 'issue/main';
                if (!empty($fav['projectid'])) {
                    $model = new ProjectModel();
                    $project = $model->getById($fav['projectid']);
                    if (isset($project['org_path'])) {
                        $issueUrl = $project['org_path'] . '/' . $project['key'];
                    }
                    unset($project);
                }
                // @todo 防止传入 fav_filter 参数进入死循环
                header('location:' . ROOT_URL . $issueUrl . '?' . $filter);
                die;
            }
        }
        // 用户的过滤器
        $IssueFavFilterLogic = new IssueFavFilterLogic();
        $data['favFilters'] = $IssueFavFilterLogic->getCurUserFavFilterByProject($data['project_id']);
        $descTplModel = new IssueDescriptionTemplateModel();
        $data['description_templates'] = $descTplModel->getAll(false);

        ConfigLogic::getAllConfigs($data);

        $data['sprints'] = [];
        $data['active_sprint'] = [];
        if (!empty($data['project_id'])) {
            $sprintModel = new SprintModel();
            $data['sprints'] = $sprintModel->getItemsByProject($data['project_id']);
            $data['active_sprint'] = $sprintModel->getActive($data['project_id']);
        }

        $this->render('gitlab/issue/list.php', $data);
    }

    /**
     * 获取某一事项的子任务列表
     * @throws \Exception
     */
    public function getChildIssue()
    {
        $issueId = null;
        if (isset($_GET['_target'][3])) {
            $issueId = (int)$_GET['_target'][3];
        }
        if (isset($_GET['id'])) {
            $issueId = (int)$_GET['id'];
        }
        if (empty($issueId)) {
            $this->ajaxFailed('参数错误', '事项id不能为空');
        }
        $issueLogic = new IssueLogic();
        $data['issues'] = $issueLogic->getChildIssue($issueId);
        $this->ajaxSuccess('ok', $data);
    }

    /**
     * 以patch方式更新事项内容
     * @throws \Exception
     */
    public function patch()
    {
        header('Content-Type:application/json');
        $issueId = null;
        if (isset($_GET['_target'][3])) {
            $issueId = (int)$_GET['_target'][3];
        }
        if (isset($_GET['id'])) {
            $issueId = (int)$_GET['id'];
        }
        if (empty($issueId)) {
            //$this->ajaxFailed('参数错误', '事项id不能为空');
        }
        $assigneeId = null;

        $_PUT = array();

        $contents = file_get_contents('php://input');
        parse_str($contents, $_PUT);
        if (isset($_PUT['issue']['assignee_id'])) {
            $assigneeId = (int)$_PUT['issue']['assignee_id'];
            if (empty($issueId) || empty($assigneeId)) {
                $ret = new \stdClass();
                echo json_encode($ret);
                die;
            }
            $issueModel = new IssueModel();
            $issue = $issueModel->getById($issueId);

            $userModel = new UserModel();
            $assignee = $userModel->getByUid($assigneeId);
            UserLogic::formatAvatarUser($assignee);
            $updateInfo = [];
            $updateInfo['assignee'] = $assigneeId;
            list($ret) = $issueModel->updateById($issueId, $updateInfo);
            if ($ret) {
                $resp = [];
                $userInfo = [];
                $userInfo['avatar_url'] = $assignee['avatar'];
                $userInfo['name'] = $assignee['display_name'];
                $userInfo['username'] = $assignee['username'];
                $resp['assignee'] = $userInfo;
                $resp['assignee_id'] = $assigneeId;
                $resp['author_id'] = $issue['creator'];
                $resp['title'] = $issue['summary'];
                echo json_encode($resp);
                die;
            }
        }
        $ret = new \stdClass();
        echo json_encode($ret);
        die;
    }

    /**
     * 事项相关的上传文件接口
     * @throws \Exception
     */
    public function upload()
    {
        $uuid = '';
        if (isset($_REQUEST['qquuid'])) {
            $uuid = $_REQUEST['qquuid'];
        }

        $originName = '';
        if (isset($_REQUEST['qqfilename'])) {
            $originName = $_REQUEST['qqfilename'];
        }

        $fileSize = 0;
        if (isset($_REQUEST['qqtotalfilesize'])) {
            $fileSize = (int)$_REQUEST['qqtotalfilesize'];
        }
        $issueId = null;
        if (isset($_REQUEST['issue_id'])) {
            $issueId = (int)$_REQUEST['issue_id'];
        }

        $summary = '';
        if (isset($_REQUEST['summary'])) {
            $summary = $_REQUEST['summary'];
        }

        $uploadLogic = new UploadLogic($issueId);

        //print_r($_FILES);
        $ret = $uploadLogic->move('qqfile', 'all', $uuid, $originName, $fileSize);
        header('Content-type: application/json; charset=UTF-8');

        $resp = [];
        if ($ret['error'] == 0) {
            $resp['success'] = true;
            $resp['error'] = '';
            $resp['url'] = $ret['url'];
            $resp['filename'] = $ret['filename'];
            $resp['origin_name'] = $ret['filename'];
            $resp['insert_id'] = $ret['insert_id'];
            $resp['uuid'] = $ret['uuid'];

            $currentUid = $this->getCurrentUid();
            $activityModel = new ActivityModel();
            $activityInfo = [];
            $activityInfo['action'] = '为' . $summary . '添加了一个附件';
            $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
            $activityInfo['obj_id'] = $issueId;
            $activityInfo['title'] = $originName;
            $activityModel->insertItem($currentUid, $issueId, $activityInfo);
        } else {
            $resp['success'] = false;
            $resp['error'] = $resp['message'];
            $resp['error_code'] = $resp['error'];
            $resp['url'] = $ret['url'];
            $resp['filename'] = $ret['filename'];
            $resp['origin_name'] = $originName;
            $resp['insert_id'] = '';
            $resp['uuid'] = $uuid;
        }
        echo json_encode($resp);
        exit;
    }

    /**
     * 删除上传的某一文件
     * @throws \Exception
     */
    public function uploadDelete()
    {
        // @todo 只有上传者和管理员才有权限删除
        $uuid = '';
        if (isset($_GET['_target'][3])) {
            $uuid = $_GET['_target'][3];
        }
        if (isset($_GET['uuid'])) {
            $uuid = $_GET['uuid'];
        }
        if ($uuid != '') {
            $model = new IssueFileAttachmentModel();
            $file = $model->getByUuid($uuid);
            if (!isset($file['uuid'])) {
                $this->ajaxFailed('uuid_not_found', []);
            }
            $ret = $model->deleteByUuid($uuid);
            if ($ret > 0) {
                $settings = Settings::getInstance()->attachment();
                // 文件保存目录路径
                $savePath = $settings['attachment_dir'];
                $unlinkRet = @unlink($savePath . '' . $file['file_name']);
                if (!$unlinkRet) {
                    $this->ajaxFailed('服务器错误', "删除文件失败,路径:" . $savePath . '' . $file['file_name']);
                }
                $this->ajaxSuccess('success', $ret);
            }
        } else {
            $this->ajaxFailed('参数错误', []);
        }
    }

    /**
     * 事项列表查询处理
     * @throws \Exception
     */
    public function filter()
    {
        $issueFilterLogic = new IssueFilterLogic();

        $pageSize = 20;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $page = max(1, $page);
        if (isset($_GET['page'])) {
            $page = max(1, intval($_GET['page']));
        }
        list($ret, $data['issues'], $total) = $issueFilterLogic->getList($page, $pageSize);
        if ($ret) {
            foreach ($data['issues'] as &$issue) {
                IssueFilterLogic::formatIssue($issue);
            }
            $data['total'] = (int)$total;
            $data['pages'] = ceil($total / $pageSize);
            $data['page_size'] = $pageSize;
            $data['page'] = $page;
            $this->ajaxSuccess('success', $data);
        } else {
            $this->ajaxFailed('failed', $data['issues']);
        }
    }

    /**
     * 获取保存过的过滤器列表
     * @throws \Exception
     */
    public function getFavFilter()
    {
        $IssueFavFilterLogic = new IssueFavFilterLogic();
        $arr['filters'] = $IssueFavFilterLogic->getCurUserFavFilter();
        $this->ajaxSuccess('success', $arr);
    }

    /**
     * 保存过滤器
     * @param string $name
     * @param string $filter
     * @param string $description
     * @param string $shared
     * @throws \Exception
     */
    public function saveFilter($name = '', $filter = '', $description = '', $shared = '')
    {
        $err = [];
        if (empty($name)) {
            $err['name'] = '名称不能为空';
        }
        if (empty($filter)) {
            $err['filter'] = '数据不能为空';
        }
        if (!empty($err)) {
            $this->ajaxFailed('参数错误', $err, BaseCtrl::AJAX_FAILED_TYPE_FORM_ERROR);
        }

        $projectId = null;
        if (isset($_REQUEST['project_id'])) {
            $projectId = (int)$_REQUEST['project_id'];
        }

        $IssueFavFilterLogic = new IssueFavFilterLogic();
        list($ret, $msg) = $IssueFavFilterLogic->saveFilter($name, $filter, $description, $shared, $projectId);
        if ($ret) {
            $this->ajaxSuccess('success', $msg);
        } else {
            $this->ajaxFailed('failed', [], $msg);
        }
    }

    /**
     * 获取某一项目的事项类型
     * @throws \Exception
     */
    public function fetchIssueType()
    {
        $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
        $logic = new IssueTypeLogic();
        $data['issue_types'] = $logic->getIssueType($projectId);
        $this->ajaxSuccess('success', $data);
    }

    /**
     * 获取事项的ui信息
     * @throws \Exception
     */
    public function fetchUiConfig()
    {
        $issueTypeId = isset($_GET['issue_type_id']) ? (int)$_GET['issue_type_id'] : null;
        $type = isset($_GET['type']) ? $_GET['type'] : 'create';
        $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

        $model = new IssueUiModel();
        $data['configs'] = $model->getsByUiType($issueTypeId, $type);

        $model = new FieldModel();
        $fields = $model->getAll(false);
        if ($fields) {
            foreach ($fields as &$v) {
                $v['options'] = json_decode($v['options']);
            }
        }
        $data['fields'] = $fields;
        $model = new FieldTypeModel();
        $data['field_types'] = $model->getAll(false);

        // 如果提交项目id则返回该项目相关的 issue_type
        $data['issue_types'] = [];
        if (!empty($projectId) && $projectId != '') {
            $logic = new IssueTypeLogic();
            $data['issue_types'] = $logic->getIssueType($projectId);
        }
        $wfLogic = new WorkflowLogic();
        $data['allow_add_status'] = $wfLogic->getStatusByProjectIssueType($projectId, $issueTypeId);

        $issueUiTabModel = new IssueUiTabModel();
        $data['tabs'] = $issueUiTabModel->getItemsByIssueTypeIdType($issueTypeId, $type);

        $this->ajaxSuccess('success', $data);
    }

    /**
     * 获取某一事项的内容，包括相关事项定义
     * @throws \Exception
     */
    public function fetchIssueEdit()
    {

        $uiType = 'edit';
        $issueId = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : null;
        $issueModel = new IssueModel();
        $issue = $issueModel->getById($issueId);

        if (empty($issue)) {
            $this->ajaxFailed('failed:issue_id is error');
        }
        $issueTypeId = (int)$issue['issue_type'];
        if (isset($_GET['issue_type'])) {
            $issueTypeId = (int)$_GET['issue_type'];
        }

        $projectId = (int)$issue['project_id'];

        $model = new IssueUiModel();
        $data['configs'] = $model->getsByUiType($issueTypeId, $uiType);

        $model = new FieldModel();
        $fields = $model->getAll(false);
        if ($fields) {
            foreach ($fields as &$field) {
                $field['options'] = json_decode($field['options']);
            }
        }
        $data['fields'] = $fields;

        $model = new FieldTypeModel();
        $data['field_types'] = $model->getAll(false);

        $model = new ProjectModuleModel();
        $data['project_module'] = $model->getByProject($projectId);

        $model = new ProjectVersionModel();
        $data['project_version'] = $model->getByProject($projectId);

        $model = new ProjectLabelModel();
        $data['issue_labels'] = $model->getAll(false);

        // 当前事项应用的标签id
        $model = new IssueLabelDataModel();
        $issueLabelData = $model->getItemsByIssueId($issueId);
        $issueLabelDataIds = [];
        foreach ($issueLabelData as $label) {
            $labelId = $label['label_id'];
            $issueLabelDataIds[] = $labelId;
        }
        $issue['labels'] = $issueLabelDataIds;

        // 当前事项应用的标签id
        $model = new IssueFixVersionModel();
        $issueFixVersion = $model->getItemsByIssueId($issueId);
        $issueFixVersionIds = [];
        foreach ($issueFixVersion as $version) {
            $issueFixVersionIds[] = $version['version_id'];
        }
        $issue['fix_version'] = $issueFixVersionIds;

        // 如果提交项目id则返回该项目相关的 issue_type
        $data['issue_types'] = [];
        if (!empty($projectId) && $projectId != '') {
            $logic = new IssueTypeLogic();
            $data['issue_types'] = $logic->getIssueType($projectId);
        }

        $model = new IssueFileAttachmentModel();
        $attachmentDataArr = $model->getsByIssueId($issueId);
        $issue['attachment'] = [];
        foreach ($attachmentDataArr as $row) {
            $file = [];
            $file['thumbnailUrl'] = ATTACHMENT_URL . $row['file_name'];
            $file['size'] = $row['file_size'];
            $file['name'] = $row['origin_name'];
            $file['uuid'] = $row['uuid'];
            $issue['attachment'][] = $file;
        }
        unset($attachmentDataArr);

        // 通过工作流获取可以变更的状态
        $logic = new WorkflowLogic();
        $issue['allow_update_status'] = $logic->getStatusByIssue($issue);

        // tab页面
        $model = new IssueUiTabModel($issueId);
        $data['tabs'] = $model->getItemsByIssueTypeIdType($issueTypeId, $uiType);

        IssueFilterLogic::formatIssue($issue);
        $data['issue'] = $issue;
        $this->ajaxSuccess('success', $data);
    }

    /**
     * 新增某一事项
     * @param array $params
     * @throws \Exception
     * @throws \Exception
     */
    public function add($params = [])
    {
        // @todo 判断权限:全局权限和项目角色
        $uid = $this->getCurrentUid();
        //检测当前用户角色权限
        //$checkPermission = Permission::getInstance( $uid ,'ADD_ISSUES' )->check();
        //if( !$checkPermission )
        //{
        //$this->ajaxFailed(Permission::$errorMsg);
        //}

        if (!isset($params['summary']) || empty(trimStr($params['summary']))) {
            $this->ajaxFailed('param_error:summary_is_null');
        }
        if (!isset($params['issue_type']) || empty(trimStr($params['issue_type']))) {
            $this->ajaxFailed('param_error:issue_type_id_is_null');
        }
        $info = [];
        $info['summary'] = $params['summary'];
        $info['creator'] = $uid;
        $info['reporter'] = $uid;
        $info['created'] = time();
        $info['updated'] = time();

        // 所属项目
        $projectId = (int)$params['project_id'];
        if (!empty($_REQUEST['project_id'])) {
            $projectId = (int)$_REQUEST['project_id'];
        }
        $model = new ProjectModel();
        $project = $model->getById($projectId);
        if (!isset($project['id'])) {
            $this->ajaxFailed('param_error:project_not_found' . $projectId);
        }

        $info['project_id'] = $projectId;

        // issue 类型
        $issueTypeId = (int)$params['issue_type'];
        $model = new IssueTypeModel();
        $issueTypes = $model->getAll();
        if (!isset($issueTypes[$issueTypeId])) {
            $this->ajaxFailed('param_error:issue_type_id_not_found');
        }
        unset($issueTypes);
        $info['issue_type'] = $issueTypeId;

        $info = $info + $this->getFormInfo($params);

        $model = new IssueModel();
        list($ret, $issueId) = $model->insert($info);
        if (!$ret) {
            $this->ajaxFailed('add_failed,error:' . $issueId);
        }

        $issueUpdateInfo = [];
        $issueUpdateInfo['pkey'] = $project['key'];
        $issueUpdateInfo['issue_num'] = $project['key'] . $issueId;
        $model->updateById($issueId, $issueUpdateInfo);

        unset($project);
        //写入操作日志
        $logData = [];
        $logData['user_name'] = $this->auth->getUser()['username'];
        $logData['real_name'] = $this->auth->getUser()['display_name'];
        $logData['obj_id'] = $issueId;
        $logData['module'] = LogOperatingLogic::MODULE_NAME_ISSUE;
        $logData['page'] = 'main';
        $logData['action'] = LogOperatingLogic::ACT_ADD;
        $logData['remark'] = '新增事项';
        $logData['pre_data'] = $info;
        $logData['cur_data'] = $info;
        LogOperatingLogic::add($uid, $projectId, $logData);

        $issueLogic = new IssueLogic();
        // 协助人
        if (isset($params['assistants'])) {
            $issueLogic->addAssistants($issueId, $params['assistants']);
        }
        // fix version
        if (isset($params['fix_version'])) {
            $model = new IssueFixVersionModel();
            $issueLogic->addChildData($model, $issueId, $params['fix_version'], 'version_id');
        }
        // labels
        if (isset($params['labels'])) {
            $model = new IssueLabelDataModel();
            $issueLogic->addChildData($model, $issueId, $params['labels'], 'label_id');
        }
        // FileAttachment
        $this->updateFileAttachment($issueId, $params);
        // 自定义字段值
        $issueLogic->addCustomFieldValue($issueId, $projectId, $params);

        $currentUid = $this->getCurrentUid();
        $activityModel = new ActivityModel();
        $activityInfo = [];
        $activityInfo['action'] = '创建了事项';
        $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
        $activityInfo['obj_id'] = $projectId;
        $activityInfo['title'] = $info['summary'];
        $activityModel->insertItem($currentUid, $projectId, $activityInfo);

        $this->ajaxSuccess('add_success', $issueId);
    }

    /**
     * 取新增或编辑时提交上来的事项内容
     * @param array $params
     * @return array
     * @throws \Exception
     */
    private function getFormInfo($params = [])
    {
        $info = [];

        // 标题
        if (isset($params['summary'])) {
            $info['summary'] = $params['summary'];
        }

        if (isset($params['issue_type'])) {
            $info['issue_type'] = (int)$params['issue_type'];
        }

        // 状态
        if (isset($params['status'])) {
            $statusId = (int)$params['status'];
            $model = new IssueStatusModel();
            $issueStatusArr = $model->getAll();
            if (!isset($issueStatusArr[$statusId])) {
                $this->ajaxFailed('param_error:status_not_found');
            }
            unset($issueStatusArr);
            $info['status'] = $statusId;
        }

        // 优先级
        if (isset($params['priority'])) {
            $priorityId = (int)$params['priority'];
            $model = new IssuePriorityModel();
            $issuePriority = $model->getAll();
            if (!isset($issuePriority[$priorityId])) {
                $this->ajaxFailed('param_error:priority_not_found');
            }
            unset($issuePriority);
            $info['priority'] = $priorityId;
        }

        // 解决结果
        if (isset($params['resolve']) && !empty($params['resolve'])) {
            $resolveId = (int)$params['resolve'];
            $model = new IssueResolveModel();
            $issueResolves = $model->getAll();
            if (!isset($issueResolves[$resolveId])) {
                $this->ajaxFailed('param_error:resolve_not_found');
            }
            unset($issueResolves);
            $info['resolve'] = $resolveId;
        }

        // 负责人
        if (isset($params['assignee']) && !empty($params['assignee'])) {
            $assigneeUid = (int)$params['assignee'];
            $model = new UserModel();
            $user = $model->getByUid($assigneeUid);
            if (!isset($user['uid'])) {
                $this->ajaxFailed('param_error:assignee_not_found');
            }
            unset($user);
            $info['assignee'] = $assigneeUid;
        }

        // 报告人
        if (isset($params['reporter']) && !empty($params['reporter'])) {
            $reporterUid = (int)$params['reporter'];
            $model = new UserModel();
            $user = $model->getByUid($reporterUid);
            if (!isset($user['uid'])) {
                $this->ajaxFailed('param_error:reporter_not_found');
            }
            unset($user);
            $info['reporter'] = $reporterUid;
        }

        if (isset($params['description'])) {
            $info['description'] = $params['description'];
        }

        if (isset($params['module'])) {
            $info['module'] = $params['module'];
        }

        if (isset($params['environment'])) {
            $info['environment'] = $params['environment'];
        }

        if (isset($params['start_date'])) {
            $info['start_date'] = $params['start_date'];
        }

        if (isset($params['due_date'])) {
            $info['due_date'] = $params['due_date'];
        }

        if (isset($params['milestone'])) {
            $info['milestone'] = (int)$params['milestone'];
        }

        if (isset($params['sprint'])) {
            $info['sprint'] = (int)$params['sprint'];
        }

        if (isset($params['weight'])) {
            $info['weight'] = (int)$params['weight'];
        }
        return $info;
    }

    /**
     * 更新事项的附件
     * @param $issueId
     * @param $params
     * @throws \Exception
     */
    private function updateFileAttachment($issueId, $params)
    {
        if (isset($params['attachment'])) {
            $model = new IssueFileAttachmentModel();
            $attachments = json_decode($params['attachment'], true);
            if (empty($attachments)) {
                $model->delete(['issue_id' => $issueId]);
            } else {
                foreach ($attachments as $file) {
                    $uuid = $file['uuid'];
                    $model->update(['issue_id' => $issueId], ['uuid' => $uuid]);
                }
            }
        }
    }

    /**
     * 更新事项的内容
     * @param $params
     * @throws \Exception
     * @throws \Exception
     */
    public function update($params)
    {
        // @todo 判断权限:全局权限和项目角色
        $issueId = null;

        if (isset($_REQUEST['issue_id'])) {
            $issueId = (int)$_REQUEST['issue_id'];
        }
        if (empty($issueId)) {
            $this->ajaxFailed('参数错误', '事项id不能为空');
        }
        $uid = $this->getCurrentUid();
        //检测当前用户角色权限
        //$checkPermission = Permission::getInstance( $uid ,'EDIT_ISSUES' )->check();
        ///if( !$checkPermission )
        //{
        //$this->ajaxFailed(Permission::$errorMsg);
        //}

        $info = [];

        if (isset($params['summary'])) {
            $info['summary'] = $params['summary'];
        }

        $info = $info + $this->getFormInfo($params);
        if (empty($info)) {
            $this->ajaxFailed('update_failed,param_error');
        }


        $issueModel = new IssueModel();
        $issue = $issueModel->getById($issueId);

        $noModified = true;
        foreach ($info as $k => $v) {
            if ($v == $issue[$k]) {
                unset($info[$k]);
            }
        }
        if ($noModified) {
            //$this->ajaxSuccess('success');
        }

        if (!empty($info)) {
            $info['modifier'] = $uid;
            list($ret, $affectedRows) = $issueModel->updateById($issueId, $info);
            if (!$ret) {
                $this->ajaxFailed('服务器错误', '更新数据失败,详情:' . $affectedRows);
            }
        }

        //写入操作日志
        $curIssue = $issue;
        foreach ($curIssue as $k => $v) {
            if (isset($info[$k])) {
                $curIssue[$k] = $info[$k];
            }
        }
        $issueLogic = new IssueLogic();
        // 协助人
        if (isset($params['assistants'])) {
            if (empty($params['assistants'])) {
                $issueLogic->emptyAssistants($issueId);
            } else {
                $issueLogic->updateAssistants($issueId, $params['assistants']);
            }
        }
        // fix version
        if (isset($params['fix_version'])) {
            $model = new IssueFixVersionModel();
            $model->delete(['issue_id' => $issueId]);
            $issueLogic->addChildData($model, $issueId, $params['fix_version'], 'version_id');
        }
        // labels
        if (isset($params['labels'])) {
            $model = new IssueLabelDataModel();
            if (empty($params['labels'])) {
                $model->delete(['issue_id' => $issueId]);
            } else {
                $issueLogic->addChildData($model, $issueId, $params['labels'], 'label_id');
            }
        }
        // FileAttachment
        $this->updateFileAttachment($issueId, $params);
        // 自定义字段值
        $issueLogic->updateCustomFieldValue($issueId, $params);

        // 活动记录
        $issueLogic = new IssueLogic();
        $statusModel = new IssueStatusModel();
        $resolveModel = new IssueResolveModel();
        $actionInfo = $issueLogic->getActivityInfo($statusModel, $resolveModel, $info);
        $currentUid = $this->getCurrentUid();
        $activityModel = new ActivityModel();
        $activityInfo = [];
        $activityInfo['action'] = $actionInfo;
        $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
        $activityInfo['obj_id'] = $issueId;
        $activityInfo['title'] = $issue['summary'];
        $activityModel->insertItem($currentUid, $issue['project_id'], $activityInfo);

        // 操作日志
        $logData = [];
        $logData['user_name'] = $this->auth->getUser()['username'];
        $logData['real_name'] = $this->auth->getUser()['display_name'];
        $logData['obj_id'] = $issueId;
        $logData['module'] = LogOperatingLogic::MODULE_NAME_ISSUE;
        $logData['page'] = 'main';
        $logData['action'] = LogOperatingLogic::ACT_EDIT;
        $logData['remark'] = '修改事项';
        $logData['pre_data'] = $issue;
        $logData['cur_data'] = $curIssue;
        LogOperatingLogic::add($uid, $issue['project_id'], $logData);

        $this->ajaxSuccess('success');
    }


    /**
     * 批量修改
     * @param $params
     * @throws \Exception
     */
    public function batchUpdate($params)
    {
        // @todo 判断权限:全局权限和项目角色
        $issueIdArr = null;
        if (isset($_REQUEST['issue_id_arr'])) {
            $issueIdArr = $_REQUEST['issue_id_arr'];
        }
        if (empty($issueIdArr)) {
            $this->ajaxFailed('参数错误', '事项id数据不能为空');
        }

        $field = null;
        if (isset($_REQUEST['field'])) {
            $field = $_REQUEST['field'];
        }
        if (empty($field)) {
            $this->ajaxFailed('参数错误', 'field数据不能为空');
        }

        $value = null;
        if (isset($_REQUEST['value'])) {
            $value = (int)$_REQUEST['value'];
        }
        if (empty($field)) {
            $this->ajaxFailed('参数错误', 'value数据不能为空');
        }

        $uid = $this->getCurrentUid();
        //检测当前用户角色权限
        //$checkPermission = Permission::getInstance( $uid ,'EDIT_ISSUES' )->check();
        ///if( !$checkPermission )
        //{
        //$this->ajaxFailed(Permission::$errorMsg);
        //}

        $info = [];
        $issueModel = new IssueModel();
        foreach ($issueIdArr as $issueId) {
            $issue = $issueModel->getById($issueId);
            $info[$field] = $value;
            list($ret, $affectedRows) = $issueModel->updateById($issueId, $info);
            if (!$ret) {
                $this->ajaxFailed('服务器错误', '更新数据失败,详情:' . $affectedRows);
            }
        }

        // 活动记录
        $issueLogic = new IssueLogic();
        $issueIds=implode(',', $issueIdArr);
        $issueNames=$issueLogic->getIssueSummary($issueIds);
        $moduleModel=new ProjectModuleModel();
        $sprintModel=new SprintModel();
        $resolveModel=new IssueResolveModel();
        $activityAction=$issueLogic->getModuleOrSprintName($moduleModel, $sprintModel, $resolveModel, $field, $value);
        $currentUid = $this->getCurrentUid();
        $activityModel = new ActivityModel();
        $activityInfo = [];
        $activityInfo['action'] = '更新了以下事项的'.$activityAction.' ';
        $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
        $activityInfo['obj_id'] = $issueId;
        $activityInfo['title'] = $issueNames;
        $activityModel->insertItem($currentUid, $issue['project_id'], $activityInfo);

        // 操作日志
        $logData = [];
        $logData['user_name'] = $this->auth->getUser()['username'];
        $logData['real_name'] = $this->auth->getUser()['display_name'];
        $logData['obj_id'] = $issueId;
        $logData['module'] = LogOperatingLogic::MODULE_NAME_ISSUE;
        $logData['page'] = 'main';
        $logData['action'] = LogOperatingLogic::ACT_EDIT;
        $logData['remark'] = '批量修改事项';
        $logData['pre_data'] = '-';
        $logData['cur_data'] = $value;
        LogOperatingLogic::add($uid, $issue['project_id'], $logData);

        $this->ajaxSuccess('success');
    }

    /**
     * 当前用户关注某一事项
     * @throws \Exception
     */
    public function follow()
    {
        $issueId = null;
        if (isset($_GET['_target'][2])) {
            $issueId = (int)$_GET['_target'][2];
        }
        if (isset($_GET['issue_id'])) {
            $issueId = (int)$_GET['issue_id'];
        }
        if (empty($issueId)) {
            $this->ajaxFailed('参数错误', '事项id不能为空');
        }
        if (empty(UserAuth::getId())) {
            $this->ajaxFailed('提示', '您尚未登录', BaseCtrl::AJAX_FAILED_TYPE_TIP);
        }

        $model = new IssueFollowModel();
        $ret = $model->add($issueId, UserAuth::getId());

        $issue = IssueModel::getInstance()->getById($issueId);

        // 活动记录
        $currentUid = $this->getCurrentUid();
        $activityModel = new ActivityModel();
        $activityInfo = [];
        $activityInfo['action'] = '关注了事项';
        $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
        $activityInfo['obj_id'] = $issueId;
        $activityInfo['title'] = $issue['summary'];
        $activityModel->insertItem($currentUid, $issue['project_id'], $activityInfo);
        $this->ajaxSuccess('success', $ret);
    }

    /**
     * 当前用户取消关注某一事项
     * @throws \Exception
     */
    public function unFollow()
    {
        $issueId = null;
        if (isset($_GET['_target'][2])) {
            $issueId = (int)$_GET['_target'][2];
        }
        if (isset($_GET['issue_id'])) {
            $issueId = (int)$_GET['issue_id'];
        }
        if (empty($issueId)) {
            $this->ajaxFailed('参数错误', '事项id不能为空');
        }
        if (empty(UserAuth::getId())) {
            $this->ajaxFailed('提示', '您尚未登录', BaseCtrl::AJAX_FAILED_TYPE_TIP);
        }
        $model = new IssueFollowModel();
        $model->deleteItemByIssueUserId($issueId, UserAuth::getId());

        // 活动记录
        $issue = IssueModel::getInstance()->getById($issueId);
        $currentUid = $this->getCurrentUid();
        $activityModel = new ActivityModel();
        $activityInfo = [];
        $activityInfo['action'] = '取消关注了事项';
        $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
        $activityInfo['obj_id'] = $issueId;
        $activityInfo['title'] = $issue['summary'];
        $activityModel->insertItem($currentUid, $issue['project_id'], $activityInfo);

        $this->ajaxSuccess('success');
    }

    /**
     * 获取子任务事项
     * @throws \Exception
     */
    public function getChildIssues()
    {
        // $uid = $this->getCurrentUid();
        //检测当前用户角色权限
        //$checkPermission = Permission::getInstance( $uid ,'DELETE_ISSUES' )->check();
        //if( !$checkPermission )
        // {
        //$this->ajaxFailed(Permission::$errorMsg);
        //}

        $issueId = null;
        if (isset($_GET['_target'][2])) {
            $issueId = (int)$_GET['_target'][2];
        }
        if (isset($_GET['issue_id'])) {
            $issueId = (int)$_GET['issue_id'];
        }
        if (empty($issueId)) {
            $this->ajaxFailed('参数错误', '事项id不能为空');
        }
        $issueLogic = new IssueLogic();
        $data['children'] = $issueLogic->getChildIssue($issueId);

        $this->ajaxSuccess('ok', $data);
    }

    /**
     * 删除事项，并进入回收站
     * @throws \Exception
     */
    public function delete()
    {

        // $uid = $this->getCurrentUid();
        //检测当前用户角色权限
        // $checkPermission = Permission::getInstance( $uid ,'DELETE_ISSUES' )->check();
        //if( !$checkPermission )
        //{
        //$this->ajaxFailed(Permission::$errorMsg);
        //}

        $issueId = null;
        if (isset($_POST['issue_id'])) {
            $issueId = (int)$_POST['issue_id'];
        }
        if (empty($issueId)) {
            $this->ajaxFailed('参数错误', '事项id不能为空');
        }

        $issueModel = new IssueModel();
        $issue = $issueModel->getById($issueId);
        if (empty($issue)) {
            $this->ajaxFailed('参数错误', 'data参数数据不能为空');
        }
        try {
            $issueModel->db->beginTransaction();
            $ret = $issueModel->deleteById($issueId);
            if ($ret) {
                $issueModel->update(['master_id' => '0'], ['master_id' => $issueId]);
                unset($issue['id']);
                $issue['delete_user_id'] = UserAuth::getId();
                $issueRecycleModel = new IssueRecycleModel();
                $info = [];
                $info['issue_id'] = $issueId;
                $info['project_id'] = $issue['project_id'];
                $info['delete_user_id'] = UserAuth::getId();
                $info['summary'] = $issue['summary'];
                $info['data'] = json_encode($issue);
                $info['time'] = time();
                list($deleteRet, $msg) = $issueRecycleModel->insert($info);
                unset($info);
                if (!$deleteRet) {
                    $issueModel->db->rollBack();
                    $this->ajaxFailed('服务器错误', '新增删除的数据失败,详情:' . $msg);
                }
            }
            $issueModel->db->commit();
        } catch (\PDOException $e) {
            $issueModel->db->rollBack();
            $this->ajaxFailed('服务器错误', '数据库异常,详情:' . $e->getMessage());
        }

        // 活动记录
        $currentUid = $this->getCurrentUid();
        $activityModel = new ActivityModel();
        $activityInfo = [];
        $activityInfo['action'] = '删除了事项';
        $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
        $activityInfo['obj_id'] = $issueId;
        $activityInfo['title'] = $issue['summary'];
        $activityModel->insertItem($currentUid, $issue['project_id'], $activityInfo);

        $this->ajaxSuccess('ok');
    }

    /**
     * 批量删除
     * @throws \Exception
     */
    public function batchDelete()
    {

        // $uid = $this->getCurrentUid();
        //检测当前用户角色权限
        // $checkPermission = Permission::getInstance( $uid ,'DELETE_ISSUES' )->check();
        //if( !$checkPermission )
        //{
        //$this->ajaxFailed(Permission::$errorMsg);
        //}

        $issueIdArr = null;
        if (isset($_REQUEST['issue_id_arr'])) {
            $issueIdArr = $_REQUEST['issue_id_arr'];
        }
        if (empty($issueIdArr)) {
            $this->ajaxFailed('参数错误', '事项id数据不能为空');
        }
        $issueModel = new IssueModel();
        $issueNames='';
        try {
            $issueLogic = new IssueLogic();
            $issueIds=implode(',', $issueIdArr);
            $issueNames=$issueLogic->getIssueSummary($issueIds);
            $issueModel->db->beginTransaction();
            foreach ($issueIdArr as $issueId) {
                $issue = $issueModel->getById($issueId);
                if (empty($issue)) {
                    continue;
                }
                $ret = $issueModel->deleteById($issueId);
                if ($ret) {
                    $issueModel->update(['master_id' => '0'], ['master_id' => $issueId]);
                    unset($issue['id']);
                    $issue['delete_user_id'] = UserAuth::getId();
                    $issueRecycleModel = new IssueRecycleModel();
                    $info = [];
                    $info['issue_id'] = $issueId;
                    $info['project_id'] = $issue['project_id'];
                    $info['delete_user_id'] = UserAuth::getId();
                    $info['summary'] = $issue['summary'];
                    $info['data'] = json_encode($issue);
                    $info['time'] = time();
                    list($deleteInsertRet, $msg) = $issueRecycleModel->insert($info);
                    unset($info);
                    if (!$deleteInsertRet) {
                        $issueModel->db->rollBack();
                        $this->ajaxFailed('服务器错误', '新增删除的数据失败,详情:' . $msg);
                    }
                }
            }
            $issueModel->db->commit();
        } catch (\PDOException $e) {
            $issueModel->db->rollBack();
            $this->ajaxFailed('服务器错误', '数据库异常,详情:' . $e->getMessage());
        }

        // 活动记录
        $currentUid = $this->getCurrentUid();
        $activityModel = new ActivityModel();
        $activityInfo = [];
        $activityInfo['action'] = '批量删除了事项: ';
        $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
        $activityInfo['obj_id'] = $issueId;
        $activityInfo['title'] = $issueNames;
        $activityModel->insertItem($currentUid, $issue['project_id'], $activityInfo);

        $this->ajaxSuccess('ok');
    }

    /**
     * 转化为子任务
     * @throws \Exception
     */
    public function convertChild()
    {
        $issueId = null;
        if (isset($_POST['issue_id'])) {
            $issueId = (int)$_POST['issue_id'];
        }
        if (empty($issueId)) {
            $this->ajaxFailed('参数错误', '事项id不能为空');
        }

        $masterId = null;
        if (isset($_POST['master_id'])) {
            $masterId = (int)$_POST['master_id'];
        }
        if (empty($masterId)) {
            $this->ajaxFailed('参数错误', '父事项id不能为空');
        }

        $issueLogic = new IssueLogic();
        list($ret, $msg) = $issueLogic->convertChild($issueId, $masterId);
        if (!$ret) {
            $this->ajaxFailed('服务器错误', '数据库异常,详情:' . $msg);
        } else {
            // 活动记录
            $issue = IssueModel::getInstance()->getById($issueId);
            $currentUid = $this->getCurrentUid();
            $activityModel = new ActivityModel();
            $activityInfo = [];
            $activityInfo['action'] = '转为子任务';
            $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
            $activityInfo['obj_id'] = $issueId;
            $activityInfo['title'] = $issue['summary'];
            $activityModel->insertItem($currentUid, $issue['project_id'], $activityInfo);

            $this->ajaxSuccess($msg);
        }
    }

    /**
     * 事项不再是子任务
     * @throws \Exception
     */
    public function removeChild()
    {
        $issueId = null;
        if (isset($_POST['issue_id'])) {
            $issueId = (int)$_POST['issue_id'];
        }
        if (empty($issueId)) {
            $this->ajaxFailed('参数错误', '事项id不能为空');
        }

        $issueLogic = new IssueLogic();
        list($ret, $msg) = $issueLogic->removeChild($issueId);
        if (!$ret) {
            $this->ajaxFailed('服务器错误', '数据库异常,详情:' . $msg);
        } else {

            // 活动记录
            $issue = IssueModel::getInstance()->getById($issueId);
            $currentUid = $this->getCurrentUid();
            $activityModel = new ActivityModel();
            $activityInfo = [];
            $activityInfo['action'] = '取消了子任务';
            $activityInfo['type'] = ActivityModel::TYPE_ISSUE;
            $activityInfo['obj_id'] = $issueId;
            $activityInfo['title'] = $issue['summary'];
            $activityModel->insertItem($currentUid, $issue['project_id'], $activityInfo);
            $this->ajaxSuccess($msg);
        }
    }


}
