<?php

namespace Biz\Notification\Event;

use Biz\CloudPlatform\IMAPIFactory;
use Codeages\Biz\Framework\Scheduler\Service\SchedulerService;
use Topxia\Api\Util\MobileSchoolUtil;
use Codeages\Biz\Framework\Event\Event;
use Codeages\PluginBundle\Event\EventSubscriber;

class PushMessageEventSubscriber extends EventSubscriber
{
    public static function getSubscribedEvents()
    {
        return array(
            'user.registered' => 'onUserCreate',
            'user.unlock' => 'onUserCreate',
            'user.lock' => 'onUserDelete',
            'user.update' => 'onUserUpdate',
            'user.change_nickname' => 'onUserUpdate',
            'user.follow' => 'onUserFollow',
            'user.unfollow' => 'onUserUnFollow',

            'classroom.join' => 'onClassroomJoin',
            'classroom.quit' => 'onClassroomQuit',

            'article.create' => 'onArticleCreate',
            //资讯在创建的时候状态就是已发布的
            'article.publish' => 'onArticleCreate',
            'article.update' => 'onArticleUpdate',
            'article.trash' => 'onArticleDelete',
            'article.unpublish' => 'onArticleDelete',
            'article.delete' => 'onArticleDelete',

            //云端不分thread、courseThread、groupThread，统一处理成字段：id, target,relationId, title, content, content, postNum, hitNum, updateTime, createdTime
            'thread.create' => 'onThreadCreate',
            'thread.update' => 'onThreadUpdate',
            'thread.delete' => 'onThreadDelete',
            'course.thread.create' => 'onCourseThreadCreate',
            'course.thread.update' => 'onCourseThreadUpdate',
            'course.thread.delete' => 'onCourseThreadDelete',
            'group.thread.create' => 'onGroupThreadCreate',
            'group.thread.open' => 'onGroupThreadOpen',
            'group.thread.update' => 'onGroupThreadUpdate',
            'group.thread.delete' => 'onGroupThreadDelete',

            'thread.post.create' => 'onThreadPostCreate',
            'thread.post.delete' => 'onThreadPostDelete',
            'course.thread.post.create' => 'onCourseThreadPostCreate',
            'course.thread.post.update' => 'onCourseThreadPostUpdate',
            'course.thread.post.delete' => 'onCourseThreadPostDelete',
            'group.thread.post.create' => 'onGroupThreadPostCreate',
            'group.thread.post.delete' => 'onGroupThreadPostDelete',

            'announcement.create' => 'onAnnouncementCreate',

            //兼容模式，courseSet映射到course
            'course-set.publish' => 'onCourseCreate',
            'course-set.update' => 'onCourseUpdate',
            'course-set.delete' => 'onCourseDelete',
            'course-set.close' => 'onCourseDelete',

            //教学计划购买
            'course.join' => 'onCourseJoin',
            'course.quit' => 'onCourseQuit',

            //兼容模式，task映射到lesson
            'course.task.publish' => 'onCourseLessonCreate',
            'course.task.unpublish' => 'onCourseLessonDelete',
            'course.task.update' => 'onCourseLessonUpdate',
            'course.task.delete' => 'onCourseLessonDelete',
        );
    }

    protected function pushCloud($eventName, array $data, $level = 'normal')
    {
        return $this->getCloudDataService()->push('school.'.$eventName, $data, time(), $level);
    }

    public function onUserUpdate(Event $event)
    {
        $context = $event->getSubject();
        if (!isset($context['user'])) {
            return;
        }
        $user = $context['user'];
        $profile = $this->getUserService()->getUserProfile($user['id']);
        $result = $this->pushCloud('user.update', $this->convertUser($user, $profile));
    }

    public function onUserFollow(Event $event)
    {
        $friend = $event->getSubject();
        $result = $this->pushCloud('user.follow', $friend);
    }

    public function onUserUnFollow(Event $event)
    {
        $friend = $event->getSubject();
        $result = $this->pushCloud('user.unfollow', $friend);
    }

    public function onUserCreate(Event $event)
    {
        $user = $event->getSubject();
        $profile = $this->getUserService()->getUserProfile($user['id']);
        $this->pushCloud('user.create', $this->convertUser($user, $profile));
    }

    public function onUserDelete(Event $event)
    {
        $user = $event->getSubject();
        $profile = $this->getUserService()->getUserProfile($user['id']);
        $this->pushCloud('user.delete', $this->convertUser($user, $profile));
    }

    protected function convertUser($user, $profile = array())
    {
        // id, nickname, title, roles, point, avatar(最大那个), about, updatedTime, createdTime
        $converted = array();
        $converted['id'] = $user['id'];
        $converted['nickname'] = $user['nickname'];
        $converted['title'] = $user['title'];

        if (!is_array($user['roles'])) {
            $user['roles'] = explode('|', $user['roles']);
        }

        $converted['roles'] = in_array('ROLE_TEACHER', $user['roles']) ? 'teacher' : 'student';
        $converted['point'] = $user['point'];
        $converted['avatar'] = $this->getFileUrl($user['largeAvatar']);
        $converted['about'] = empty($profile['about']) ? '' : $profile['about'];
        $converted['updatedTime'] = $user['updatedTime'];
        $converted['createdTime'] = $user['createdTime'];

        return $converted;
    }

    public function onCoursePublish(Event $event)
    {
        $course = $event->getSubject();
        $this->pushCloud('course.create', $this->convertCourse($course));
    }

    public function onCourseCreate(Event $event)
    {
        $course = $event->getSubject();
        $this->pushCloud('course.create', $this->convertCourse($course));
    }

    public function onCourseUpdate(Event $event)
    {
        $course = $event->getSubject();
        $this->pushCloud('course.update', $this->convertCourse($course));
    }

    public function onCourseDelete(Event $event)
    {
        $course = $event->getSubject();

        $this->pushCloud('course.delete', $this->convertCourse($course));
    }

    public function onCourseJoin(Event $event)
    {
        $course = $event->getSubject();
        $userId = $event->getArgument('userId');
        $member = $event->getArgument('member');

        if (!empty($course['parentId'])) {
            return;
        }

        $member['course'] = $this->convertCourse($course);
        $member['user'] = $this->convertUser($this->getUserService()->getUser($userId));

        $this->pushCloud('course.join', $member, 'important');
    }

    public function onCourseQuit(Event $event)
    {
        $course = $event->getSubject();
        $userId = $event->getArgument('userId');
        $member = $event->getArgument('member');

        if (!empty($course['parentId'])) {
            return;
        }

        $member['course'] = $this->convertCourse($course);
        $member['user'] = $this->convertUser($this->getUserService()->getUser($userId));

        $this->pushCloud('course.quit', $member, 'important');
    }

    protected function convertCourse($course)
    {
        $course['smallPicture'] = isset($course['cover']['small']) ? $this->getFileUrl($course['cover']['small']) : '';
        $course['middlePicture'] = isset($course['cover']['middle']) ? $this->getFileUrl($course['cover']['middle']) : '';
        $course['largePicture'] = isset($course['cover']['large']) ? $this->getFileUrl($course['cover']['large']) : '';
        $course['about'] = isset($course['summary']) ? $this->convertHtml($course['summary']) : '';

        return $course;
    }

    protected function convertOpenCourse($openCourse)
    {
        $openCourse['smallPicture'] = $this->getFileUrl($openCourse['smallPicture']);
        $openCourse['middlePicture'] = $this->getFileUrl($openCourse['middlePicture']);
        $openCourse['largePicture'] = $this->getFileUrl($openCourse['largePicture']);
        $openCourse['about'] = $this->convertHtml($openCourse['about']);

        return $openCourse;
    }

    /**
     * CourseLesson相关.
     */
    public function onCourseLessonCreate(Event $event)
    {
        $lesson = $event->getSubject();

        $mobileSetting = $this->getSettingService()->get('mobile');

        if ((!isset($mobileSetting['enable']) || $mobileSetting['enable']) && $lesson['type'] == 'live') {
            $this->createJob($lesson);
        }

        $this->pushCloud('lesson.create', $lesson);
    }

    public function onCourseLessonUpdate(Event $event)
    {
        $lesson = $event->getSubject();
        $oldTask = $event->getArguments();
        $mobileSetting = $this->getSettingService()->get('mobile');

        $shouldReCreatePushJOB = $lesson['type'] == 'live' && isset($oldTask['startTime']) && $oldTask['startTime'] != $lesson['startTime'] && (!isset($mobileSetting['enable']) || $mobileSetting['enable']);
        if ($shouldReCreatePushJOB) {
            $jobs = $this->getCrontabService()->findJobByTargetTypeAndTargetId('lesson', $lesson['id']);
            if ($jobs) {
                $this->deleteJob($jobs);
            }

            if ($lesson['status'] == 'published') {
                $this->createJob($lesson);
            }
        }

        $this->pushCloud('lesson.update', $lesson);
    }

    public function onCourseLessonDelete(Event $event)
    {
        $context = $event->getSubject();
        if (isset($context['lesson'])) {
            $lesson = $context['lesson'];
        } else {
            $lesson = $context;
        }

        $this->deleteJob($lesson);

        $this->pushCloud('lesson.delete', $lesson);
    }

    public function onClassroomJoin(Event $event)
    {
        $classroom = $event->getSubject();
        $userId = $event->getArgument('userId');
        $member = $event->getArgument('member');

        $member['classroom'] = $this->convertClassroom($classroom);
        $member['user'] = $this->convertUser($this->getUserService()->getUser($userId));

        $this->pushCloud('classroom.join', $member, 'important');
    }

    public function onClassroomQuit(Event $event)
    {
        $classroom = $event->getSubject();
        $userId = $event->getArgument('userId');
        $member = $event->getArgument('member');

        $member['classroom'] = $this->convertClassroom($classroom);
        $member['user'] = $this->convertUser($this->getUserService()->getUser($userId));

        $this->pushCloud('classroom.quit', $member, 'important');
    }

    protected function convertClassroom($classroom)
    {
        $classroom['smallPicture'] = $this->getFileUrl($classroom['smallPicture']);
        $classroom['middlePicture'] = $this->getFileUrl($classroom['middlePicture']);
        $classroom['largePicture'] = $this->getFileUrl($classroom['largePicture']);
        $classroom['about'] = $this->convertHtml($classroom['about']);

        return $classroom;
    }

    /**
     * Article相关.
     */
    public function onArticleCreate(Event $event)
    {
        $article = $event->getSubject();

        $schoolUtil = new MobileSchoolUtil();

        $articleApp = $schoolUtil->getArticleApp();
        $articleApp['avatar'] = $this->getAssetUrl($articleApp['avatar']);
        $article['app'] = $articleApp;

        $imSetting = $this->getSettingService()->get('app_im', array());
        $article['convNo'] = isset($imSetting['convNo']) && !empty($imSetting['convNo']) ? $imSetting['convNo'] : '';
        $article = $this->convertArticle($article);
        $article['_noPushIM'] = 1;

        $this->pushCloud('article.create', $article);

        $from = array(
            'id' => $article['app']['id'],
            'type' => $article['app']['code'],
        );

        $to = array(
            'type' => 'global',
            'convNo' => empty($article['convNo']) ? '' : $article['convNo'],
        );

        $body = array(
            'type' => 'news.create',
            'id' => $article['id'],
            'title' => $article['title'],
            'image' => $article['thumb'],
            'content' => $this->plainText($article['body'], 50),
        );

        $this->pushIM($from, $to, $body);
    }

    public function onArticleUpdate(Event $event)
    {
        $article = $event->getSubject();
        $this->pushCloud('article.update', $this->convertArticle($article));
    }

    public function onArticleDelete(Event $event)
    {
        $article = $event->getSubject();
        $this->pushCloud('article.delete', $this->convertArticle($article));
    }

    protected function convertArticle($article)
    {
        $article['thumb'] = $this->getFileUrl($article['thumb']);
        $article['originalThumb'] = $this->getFileUrl($article['originalThumb']);
        $article['picture'] = $this->getFileUrl($article['picture']);
        $article['body'] = $article['title'];

        return $article;
    }

    /**
     * Thread相关.
     */
    public function onThreadCreate(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.create', $this->convertThread($thread, 'thread.create'));
    }

    public function onGroupThreadCreate(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.create', $this->convertThread($thread, 'group.thread.create'));
    }

    public function onGroupThreadOpen(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.create', $this->convertThread($thread, 'group.thread.open'));
    }

    public function onCourseThreadCreate(Event $event)
    {
        $thread = $event->getSubject();
        $thread = $this->convertThread($thread, 'course.thread.create');

        $thread['_noPushIM'] = 1;

        $this->pushCloud('thread.create', $thread);

        if ($thread['target']['type'] != 'course' || $thread['type'] != 'question') {
            return;
        }

        $from = array(
            'type' => $thread['target']['type'],
            'id' => $thread['target']['id'],
        );

        $to = array(
            'type' => 'user',
            'convNo' => empty($thread['target']['convNo']) ? '' : $thread['target']['convNo'],
        );

        $body = array(
            'type' => 'question.created',
            'threadId' => $thread['id'],
            'courseId' => $thread['target']['id'],
            'lessonId' => $thread['relationId'],
            'questionCreatedTime' => $thread['createdTime'],
            'questionTitle' => $thread['title'],
            'title' => "{$thread['target']['title']} 有新问题",
        );

        $results = array();

        foreach (array_values($thread['target']['teacherIds']) as $i => $teacherId) {
            if ($i >= 3) {
                break;
            }
            $to['id'] = $teacherId;
            $results[] = $this->pushIM($from, $to, $body);
        }
    }

    public function onThreadUpdate(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.update', $this->convertThread($thread, 'thread.update'));
    }

    public function onCourseThreadUpdate(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.update', $this->convertThread($thread, 'course.thread.update'));
    }

    public function onGroupThreadUpdate(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.update', $this->convertThread($thread, 'group.thread.update'));
    }

    public function onThreadDelete(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.delete', $this->convertThread($thread, 'thread.delete'));
    }

    public function onCourseThreadDelete(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.delete', $this->convertThread($thread, 'course.thread.delete'));
    }

    public function onGroupThreadDelete(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.delete', $this->convertThread($thread, 'group.thread.delete'));
    }

    public function onGroupThreadClose(Event $event)
    {
        $thread = $event->getSubject();
        $this->pushCloud('thread.delete', $this->convertThread($thread, 'group.thread.close'));
    }

    protected function convertThread($thread, $eventName)
    {
        if (strpos($eventName, 'course') === 0) {
            $thread['targetType'] = 'course';
            $thread['targetId'] = $thread['courseId'];
            $thread['relationId'] = $thread['taskId'];
        } elseif (strpos($eventName, 'group') === 0) {
            $thread['targetType'] = 'group';
            $thread['targetId'] = $thread['groupId'];
            $thread['relationId'] = 0;
        }

        // id, target, relationId, title, content, postNum, hitNum, updateTime, createdTime
        $converted = array();

        $converted['id'] = $thread['id'];
        $converted['target'] = $this->getTarget($thread['targetType'], $thread['targetId']);
        $converted['relationId'] = $thread['relationId'];
        $converted['type'] = empty($thread['type']) ? 'none' : $thread['type'];
        $converted['userId'] = empty($thread['userId']) ? 0 : $thread['userId'];
        $converted['title'] = $thread['title'];
        $converted['content'] = $this->convertHtml($thread['content']);
        $converted['postNum'] = $thread['postNum'];
        $converted['hitNum'] = $thread['hitNum'];
        $converted['updateTime'] = isset($thread['updateTime']) ? $thread['updateTime'] : $thread['updatedTime'];
        $converted['createdTime'] = $thread['createdTime'];

        return $converted;
    }

    /**
     * ThreadPost相关.
     */
    public function onThreadPostCreate(Event $event)
    {
        $threadPost = $event->getSubject();
        $this->pushCloud('thread_post.create', $this->convertThreadPost($threadPost, 'thread.post.create'));
    }

    public function onCourseThreadPostCreate(Event $event)
    {
        $threadPost = $event->getSubject();
        $this->pushCloud('thread_post.create', $this->convertThreadPost($threadPost, 'course.thread.post.create'));
    }

    public function onGroupThreadPostCreate(Event $event)
    {
        $post = $event->getSubject();
        $post = $this->convertThreadPost($post, 'group.thread.post.create');

        $post['_noPushIM'] = 1;
        $this->pushCloud('thread_post.create', $post);

        if ($post['target']['type'] != 'course' || empty($post['target']['teacherIds'])) {
            return;
        }

        if ($post['thread']['type'] != 'question') {
            return;
        }

        foreach ($post['target']['teacherIds'] as $teacherId) {
            if ($teacherId != $post['userId']) {
                continue;
            }

            $from = array(
                'type' => $post['target']['type'],
                'id' => $post['target']['id'],
            );

            $to = array(
                'type' => 'user',
                'id' => $post['thread']['userId'],
                'convNo' => empty($post['target']['convNo']) ? '' : $post['target']['convNo'],
            );

            $body = array(
                'type' => 'question.answered',
                'threadId' => $post['threadId'],
                'courseId' => $post['target']['id'],
                'lessonId' => $post['thread']['relationId'],
                'questionCreatedTime' => $post['thread']['createdTime'],
                'questionTitle' => $post['thread']['title'],
                'postContent' => $post['content'],
                'title' => "{$post['thread']['title']} 有新回复",
            );

            $this->pushIM($from, $to, $body);
        }
    }

    public function onCourseThreadPostUpdate(Event $event)
    {
        $threadPost = $event->getSubject();
        $this->pushCloud('thread_post.update', $this->convertThreadPost($threadPost, 'course.thread.post.update'));
    }

    public function onThreadPostDelete(Event $event)
    {
        $threadPost = $event->getSubject();
        $this->pushCloud('thread_post.delete', $this->convertThreadPost($threadPost, 'thread.post.delete'));
    }

    public function onCourseThreadPostDelete(Event $event)
    {
        $threadPost = $event->getSubject();
        $this->pushCloud('thread_post.delete', $this->convertThreadPost($threadPost, 'course.thread.post.delete'));
    }

    public function onGroupThreadPostDelete(Event $event)
    {
        $threadPost = $event->getSubject();
        $this->pushCloud('thread_post.delete', $this->convertThreadPost($threadPost, 'group.thread.post.delete'));
    }

    protected function convertThreadPost($threadPost, $eventName)
    {
        if (strpos($eventName, 'course') === 0) {
            $threadPost['targetType'] = 'course';
            $threadPost['targetId'] = $threadPost['courseId'];
            $threadPost['thread'] = $this->convertThread(
                $this->getThreadService('course')->getThread($threadPost['courseId'], $threadPost['threadId']),
                $eventName
            );
        } elseif (strpos($eventName, 'group') === 0) {
            $thread = $this->getThreadService('group')->getThread($threadPost['threadId']);
            $threadPost['targetType'] = 'group';
            $threadPost['targetId'] = $thread['groupId'];
            $threadPost['thread'] = $this->convertThread($thread, $eventName);
        } else {
            $threadPost['thread'] = $this->convertThread(
                $this->getThreadService()->getThread($threadPost['threadId']),
                $eventName
            );
        }

        // id, threadId, content, userId, createdTime, target, thread
        $converted = array();

        $converted['id'] = $threadPost['id'];
        $converted['threadId'] = $threadPost['threadId'];
        $converted['content'] = $this->convertHtml($threadPost['content']);
        $converted['userId'] = $threadPost['userId'];
        $converted['target'] = $this->getTarget($threadPost['targetType'], $threadPost['targetId']);
        $converted['thread'] = $threadPost['thread'];
        $converted['createdTime'] = $threadPost['createdTime'];

        return $converted;
    }

    /**
     * Announcement相关.
     */
    public function onAnnouncementCreate(Event $event)
    {
        $announcement = $event->getSubject();

        $target = $this->getTarget($announcement['targetType'], $announcement['targetId']);
        $announcement['target'] = $target;
        $announcement['_noPushIM'] = 1;

        $this->pushCloud('announcement.create', $announcement);

        $from = array(
            'type' => $target['type'],
            'id' => $target['id'],
        );

        $to = array(
            'type' => $target['type'],
            'id' => $target['id'],
            'convNo' => empty($target['convNo']) ? '' : $target['convNo'],
        );

        $body = array(
            'id' => $announcement['id'],
            'type' => 'announcement.create',
            'title' => $this->plainText($announcement['content'], 50),
        );

        $this->pushIM($from, $to, $body);
    }

    public function onOpenCourseCreate(Event $event)
    {
        $openCourse = $event->getSubject();
        $this->pushCloud('openCourse.create', $this->convertOpenCourse($openCourse));
    }

    public function onOpenCourseDelete(Event $event)
    {
        $openCourse = $event->getSubject();
        $this->pushCloud('openCourse.delete', $this->convertOpenCourse($openCourse));
    }

    public function onOpenCourseUpdate(Event $event)
    {
        $subject = $event->getSubject();
        $course = $subject['course'];
        $this->pushCloud('openCourse.update', $this->convertOpenCourse($course));
    }

    protected function getTarget($type, $id)
    {
        $target = array('type' => $type, 'id' => $id);

        switch ($type) {
            case 'course':
                $course = $this->getCourseService()->getCourse($id);
                $courseSet = $this->getCourseSetService()->getCourseSet($course['courseSetId']);
                $target['title'] = $course['title'];
                $target['image'] = empty($courseSet['cover']['small']) ? '' : $this->getFileUrl(
                    $courseSet['cover']['small']
                );
                $target['teacherIds'] = empty($course['teacherIds']) ? array() : $course['teacherIds'];
                $conv = $this->getConversationService()->getConversationByTarget($id, 'course-push');
                $target['convNo'] = empty($conv) ? '' : $conv['no'];
                break;
            case 'lesson':
                $task = $this->getTaskService()->getTask($id);
                $target['title'] = $task['title'];
                break;
            case 'classroom':
                $classroom = $this->getClassroomService()->getClassroom($id);
                $target['title'] = $classroom['title'];
                $target['image'] = $this->getFileUrl($classroom['smallPicture']);
                break;
            case 'group':
                $group = $this->getGroupService()->getGroup($id);
                $target['title'] = $group['title'];
                $target['image'] = $this->getFileUrl($group['logo']);
                break;
            case 'global':
                $schoolUtil = new MobileSchoolUtil();
                $schoolApp = $schoolUtil->getAnnouncementApp();
                $target['title'] = '网校公告';
                $target['id'] = $schoolApp['id'];
                $target['image'] = $this->getFileUrl($schoolApp['avatar']);
                $setting = $this->getSettingService()->get('app_im', array());
                $target['convNo'] = empty($setting['convNo']) ? '' : $setting['convNo'];
                break;
            default:
                // code...
                break;
        }

        return $target;
    }

    protected function getFileUrl($path)
    {
        if (empty($path)) {
            return $path;
        }

        $path = str_replace('public://', '', $path);
        $path = str_replace('files/', '', $path);
        $path = "http://{$_SERVER['HTTP_HOST']}/files/{$path}";

        return $path;
    }

    protected function getAssetUrl($path)
    {
        if (empty($path)) {
            return '';
        }

        $path = "http://{$_SERVER['HTTP_HOST']}/assets/{$path}";

        return $path;
    }

    protected function convertHtml($text)
    {
        preg_match_all('/\<img.*?src\s*=\s*[\'\"](.*?)[\'\"]/i', $text, $matches);

        if (empty($matches)) {
            return $text;
        }

        foreach ($matches[1] as $url) {
            $text = str_replace($url, $this->getFileUrl($url), $text);
        }

        return $text;
    }

    protected function plainText($text, $count)
    {
        return mb_substr($text, 0, $count, 'utf-8');
    }

    protected function createJob($lesson)
    {
        if ($lesson['startTime'] >= (time() + 60 * 60)) {
            $startJob = array(
                'name' => 'PushNotificationOneHourJob_lesson_'.$lesson['id'],
                'expression' => $lesson['startTime'] - 60 * 60,
                'class' => 'Biz\Notification\Job\PushNotificationOneHourJob',
                'args' => array(
                    'targetType' => 'lesson',
                    'targetId' => $lesson['id'],
                ),
            );
            $this->getSchedulerService()->register($startJob);
        }

        if ($lesson['type'] == 'live') {
            $startJob = array(
                'name' => 'LiveCourseStartNotifyJob_liveLesson_'.$lesson['id'],
                'expression' => $lesson['startTime'] - 10 * 60,
                'class' => 'Biz\Notification\Job\LiveLessonStartNotifyJob',
                'args' => array(
                    'targetType' => 'liveLesson',
                    'targetId' => $lesson['id'],
                ),
            );
            $this->getSchedulerService()->register($startJob);
        }
    }

    protected function deleteJob($lesson)
    {
        $this->getSchedulerService()->deleteJobByName('PushNotificationOneHourJob_lesson_'.$lesson['id']);

        if ('live' == $lesson['type']) {
            $this->getSchedulerService()->deleteJobByName('LiveCourseStartNotifyJob_liveLesson_'.$lesson['id']);
        }
    }

    /**
     * @return SchedulerService
     */
    protected function getSchedulerService()
    {
        return $this->createService('Scheduler:SchedulerService');
    }

    protected function getThreadService($type = '')
    {
        if ($type == 'course') {
            return $this->createService('Course:ThreadService');
        }

        if ($type == 'group') {
            return $this->createService('Group:ThreadService');
        }

        return $this->createService('Thread:ThreadService');
    }

    protected function getCourseService()
    {
        return $this->createService('Course:CourseService');
    }

    protected function getCourseSetService()
    {
        return $this->createService('Course:CourseSetService');
    }

    protected function getTaskService()
    {
        return $this->createService('Task:TaskService');
    }

    protected function getClassroomService()
    {
        return $this->createService('Classroom:ClassroomService');
    }

    protected function getUserService()
    {
        return $this->createService('User:UserService');
    }

    protected function getTestpaperService()
    {
        return $this->createService('Testpaper:TestpaperService');
    }

    protected function getCloudDataService()
    {
        return $this->createService('CloudData:CloudDataService');
    }

    protected function getSettingService()
    {
        return $this->createService('System:SettingService');
    }

    //TODO
    protected function getHomeworkService()
    {
        return $this->createService('Homework:Homework.HomeworkService');
    }

    protected function getGroupService()
    {
        return $this->createService('Group:GroupService');
    }

    protected function getConversationService()
    {
        return $this->createService('IM:ConversationService');
    }

    protected function createService($alias)
    {
        return $this->getBiz()->service($alias);
    }

    protected function pushIM($from, $to, $body)
    {
        $setting = $this->getSettingService()->get('app_im', array());
        if (empty($setting['enabled'])) {
            return;
        }

        $params = array(
            'fromId' => 0,
            'fromName' => '系统消息',
            'toName' => '全部',
            'body' => array(
                'v' => 1,
                't' => 'push',
                'b' => $body,
                's' => $from,
                'd' => $to,
            ),
            'convNo' => empty($to['convNo']) ? '' : $to['convNo'],
        );

        if ($to['type'] == 'user') {
            $params['toId'] = $to['id'];
        }

        if (empty($params['convNo'])) {
            return;
        }

        try {
            $api = IMAPIFactory::create();
            $result = $api->post('/push', $params);

            $setting = $this->getSettingService()->get('developer', array());
            if (!empty($setting['debug'])) {
                IMAPIFactory::getLogger()->debug('API RESULT', !is_array($result) ? array() : $result);
            }
        } catch (\Exception $e) {
            IMAPIFactory::getLogger()->warning('API REQUEST ERROR:'.$e->getMessage());
        }
    }
}
