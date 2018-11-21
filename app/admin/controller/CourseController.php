<?php
// +----------------------------------------------------------------------
// | ThinkCMF [ WE CAN DO IT MORE SIMPLE ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013-2018 http://www.thinkcmf.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: 小夏 < 449134904@qq.com>
// +----------------------------------------------------------------------
namespace app\admin\controller;

use app\admin\model\ExamItemModel;
use app\admin\model\CourseModel;
use cmf\controller\AdminBaseController;
use app\admin\model\CategoryModel;
use think\Cookie;
use think\Db;

class CourseController extends AdminBaseController
{
    public $type=3; //category 表中type=1的分类
    public $status = [-1=>'删除', 0=>'未发布', 1=>'已发布'];
    public $item_type = [1=>'选择题', 2=>'填空题', 3=>'论述题'];

    public function _initialize()
    {
        parent::_initialize();
        $this->assign('type', $this->type);
        $this->assign('status' ,$this->status);
        $this->assign('item_type' ,$this->item_type);
    }

    public function index()
    {
        $where = [];
        /**搜索条件**/
        $keyword = $this->request->param('keyword');
        $property = $this->request->param('property', '', 'intval');
        if ($keyword) {
            $where[] = ['ctitile', 'like', "%{$keyword}%"];
        }
        if ($property) {
            $where['property'] = $property;
        }

        $list = DB::name('Course')
            ->where($where)
            ->order("cid DESC")
            ->paginate();
        // 分页注入搜索条件
        // 获取关联的讲师 tid
        $cids = array_column($list->items(), 'cid');
        if ($cids) {
            $teachers = DB::name('course_teacher_relation a')
                ->join('__COURSE_TEACHER__ b', 'a.tid=b.tid')
                ->where(['a.cid'=>['IN', $cids]])
                ->field(['a.cid','b.tid', 'b.tname'])
                ->select()
                ->toArray();
            $handle_teacher = [];
            foreach($teachers as $key=>$item) {
                $handle_teacher[$item['cid']][] = $item['tname'];
            }
            $list = $list->each(function ($item, $key) use ($handle_teacher) {
                if ($handle_teacher[$item['cid']]) {
                    $item['tnames'] = join(',', $handle_teacher[$item['cid']]);
                } else {
                    $item['tnames'] = '';
                }
                return $item;
            });
        }
        $list->appends(['keyword' => $keyword, 'property' => $property]);
        // 获取分页显示
        $page = $list->render();
        $this->assign(['keyword' => $keyword, 'property' => $property]);
        $this->assign("page", $page);
        $this->assign("list", $list);
        //dump($list);die;
        return $this->fetch();
    }

    /**
     * 编辑
     * @return mixed
     */
    public function edit()
    {
        $id    = $this->request->param('cid', 0, 'intval');
        $info = $tids = [];
        if ($id) {
            $info = DB::name('course')->where(["cid" => $id])->find();
            $tids = DB::name('course_teacher_relation')->where(['cid'=>$id, 'status'=>1])->column('tid');
        }
        $CategoryModel = new CategoryModel();
        $categoryTree = $CategoryModel->categoryTree(isset($info['pid']) ? $info['pid']: 0, '', $this->type);
        $this->assign('category_tree', $categoryTree);

        //讲师
        $teachers = DB::name('course_teacher')->where(['status'=>1])->select();
        $this->assign('teachers', $teachers);
        $this->assign($info);
        $this->assign('select_tids', $tids ? json_encode($tids): '');
        $this->assign('tids_str', implode(',', $tids));
        return $this->fetch();
    }

    /**
     * 保存
     */
    public function editPost()
    {
        $id = $this->request->param('cid');
        $data = $this->request->param();
        $result = $this->validate($data, 'Course');
        if ($result !== true) {
            $this->error($result);
        }
        $CourseModel = new CourseModel();
        $tid = explode(',', $data['tid']);

        if ($id) {
            //save
            Db::startTrans();
            try{
                $CourseModel->isUpdate(true)->allowField(true)->save($data);
                DB::table('st_course_teacher_relation')->where(['cid'=>$id])->delete();
                $relation_list = array_map(function($item) use ($id) {
                    return [
                        'cid'=>$id,
                        'tid'=>$item,
                        'status'=>1
                    ];
                }, $tid);
                Db::table('st_course_teacher_relation')->insertAll($relation_list);
                // 提交事务
                Db::commit();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                $this->error($e->getMessage());
            }
            $this->success('编辑成功!', url('course/index'));
        } else {
            //add
            Db::startTrans();
            try{
                $CourseModel->isUpdate(false)->allowField(true)->save($data);
                $cid = $CourseModel->cid;
                $relation_list = array_map(function($item) use ($cid) {
                    return [
                        'cid'=>$cid,
                        'tid'=>$item,
                        'status'=>1
                    ];
                }, $tid);
                Db::table('st_course_teacher_relation')->insertAll($relation_list);
                // 提交事务
                Db::commit();
                //$this->
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                $this->error($e->getMessage());
            }
            $this->success('添加成功!', url('course/index'));
        }
    }

    /**
     * 详细题目列表
     */
    public function detail() {
        $id = $this->request->param('id', 0, 'intval');
        if (!$id) $this->error('请选择一套试卷');
        $where = ['exam_id'=>$id, 'status'=>1];
        $exams_items = DB::name('Exam_item')
            ->where($where)
            ->order("list_order ASC,id ASC")
            ->select();
        $this->assign('list' , $exams_items);
        //获取试卷信息
        $info = DB::name('exam')->where(['id'=>$id])->find();
        $this->assign('info', $info);
        return $this->fetch();
    }

    /**
     * 编辑题目
     */
    public function editItem() {
        $type = $this->request->param('item_type', 0, 'intval');
        $exam_id = $this->request->param('exam_id', 0, 'intval');
        $item_id = $this->request->param('item_id', 0, 'intval');
        if (!$exam_id) $this->error('试卷id不能为空');
        switch ($type) {
            case 1 :
                $template_name = 'edit_item_xuanze';
                break;
            case 2 :
                $template_name = 'edit_item_tiankong';
                break;
            case 3 :
                $template_name = 'edit_item_lunshu';
                break;
            default :
                $cookie_type_name = $this->request->cookie('item_template_name');
                if ($cookie_type_name) {
                    $template_name = $cookie_type_name;
                } else {
                    $template_name = 'edit_item_xuanze';
                }
        }
        Cookie::set('item_template_name', $template_name, 86400);
        //题目信息
        $info = [];
        if ($item_id) {
            $info = DB::name('exam_item')->where(['id'=>$item_id])->find();
            if ($info['option']) $info['option'] = json_decode($info['option'], true);
        }
        $this->assign('info', $info);
        return $this->fetch($template_name);
    }

    /**
     * 保存题目
     */
    public function saveItem() {
        $id = $this->request->param('item_id');
        $data = $this->request->param()['post'];
        $result = $this->validate($data, 'ExamItem');
        if ($result !== true) {
            $this->error($result);
        }
        $ExamItemModel = new ExamItemModel();
        if (isset($data['option']) && $data['option']) $data['option'] = json_encode($data['option'], 64|256);
        if ($id) {
            //save
            $data['id'] = $id;
            $result = $ExamItemModel->allowField(true)->isUpdate(true)->save($data);
            if ($result === false) {
                $this->error('编辑失败!');
            } else {
                $this->success('编辑成功!', url('exam/editItem', ['item_id'=>$id, 'item_type'=>$data['type'], 'exam_id'=>$data['exam_id']]));
            }
        } else {
            //add
            unset($data['id']);
            $result = $ExamItemModel->allowField(true)->save($data);
            if ($result === false) {
                $this->error('添加失败!');
            }
            $this->success('添加成功!', url('exam/editItem', ['item_id'=>$result, 'item_type'=>$data['type'], 'exam_id'=>$data['exam_id']]));
        }
    }

    public function listOrder()
    {
        parent::listOrders(Db::name('exam_item'));
        $this->success("排序更新成功！", '');
    }


    public function toggle()
    {
        $data                = $this->request->param();
        $examItemModel = new ExamItemModel();

        if (isset($data['ids']) && !empty($data["display"])) {
            $ids = $this->request->param('ids/a');
            $examItemModel->where(['id' => ['in', $ids]])->update(['status' => 1]);
            $this->success("更新成功！");
        }

        if (isset($data['ids']) && !empty($data["hide"])) {
            $ids = $this->request->param('ids/a');
            $examItemModel->where(['id' => ['in', $ids]])->update(['status' => 0]);
            $this->success("更新成功！");
        }

    }

    public function delete()
    {
        $id = $this->request->param('id', 0, 'intval');
        if (Db::name('exam')->where(['id'=> $id])->update(['status' => -1]) !== false) {
            $this->success("删除成功！");
        } else {
            $this->error("删除失败！");
        }
    }

    public function delete_item()
    {
        $id = $this->request->param('id', 0, 'intval');
        if (Db::name('exam_item')->where(['id'=> $id])->update(['status' => -1]) !== false) {
            $this->success("删除成功！");
        } else {
            $this->error("删除失败！");
        }
    }

    public function teacher() {
        $where = [];
        /**搜索条件**/
        $keyword = $this->request->param('keyword');
        $where['status'] = ['EGT', 0];
        if ($keyword) {
            $where['tname'] = ['like', "%{$keyword}%"];
        }

        $list = DB::name('course_teacher')
            ->where($where)
            ->order("tid DESC")
            ->paginate();
        // 分页注入搜索条件
        $list->appends(['keyword' => $keyword]);
        // 获取分页显示
        $page = $list->render();
        $this->assign(['keyword' => $keyword]);
        $this->assign("page", $page);
        $this->assign("list", $list);
        return $this->fetch();
    }

    public function teacherEdit() {
        $tid = $this->request->param('tid', 0, 'intval');
        //题目信息
        $info = ['status'=>1];
        if ($tid) {
            $info = DB::name('course_teacher')->where(['tid'=>$tid])->find();
        }
        $this->assign($info);
        return $this->fetch();
    }

    public function teacherSave() {
        $tid = $this->request->param('tid');
        $data = $this->request->param();
        $result = $this->validate($data, 'CourseTeacher');
        if ($result !== true) {
            $this->error($result);
        }
        if ($tid) {
            //save
            $data['tid'] = $tid;
            $result = DB::name('course_teacher')->where(['tid'=>$tid])->update($data);

            if ($result === false) {
                $this->error('编辑失败!');
            } else {
                $this->success('编辑成功!', url('course/teacherEdit', ['tid'=>$tid]));
            }
        } else {
            //add
            unset($data['id']);
            $result = Db::name('course_teacher')->insert($data);
            if ($result === false) {
                $this->error('添加失败!');
            }
            $this->success('添加成功!', url('course/teacher'));
        }
    }

    public function teacherDelete() {
        $tid = $this->request->param('tid');
        if (!$tid) $this->error('没有讲师id!');
        if (Db::name('course_teacher')->where(['tid'=> $tid])->update(['status' => 0]) !== false) {
            $this->success("删除成功！");
        } else {
            $this->error("删除失败！");
        }
    }
}