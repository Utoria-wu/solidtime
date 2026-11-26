import { useStorage } from '@vueuse/core';
import { computed, watchEffect } from 'vue';

const zhCN: Record<string, string> = {
    Dashboard: '仪表盘',
    Time: '计时',
    Calendar: '日历',
    Timesheet: '工时表',
    Reporting: '报表',
    Overview: '概览',
    Detailed: '明细',
    Shared: '共享报表',
    Goals: '目标',
    Manage: '管理',
    Admin: '系统管理',
    Projects: '项目',
    Clients: '客户',
    Members: '成员',
    Tags: '标签',
    Invoices: '发票',
    Billing: '账单',
    'Import / Export': '导入 / 导出',
    Settings: '设置',
    'Profile Settings': '个人设置',
    'Interface language': '界面语言',
    'Create Project': '新建项目',
    List: '列表',
    Schedule: '排期',
    'Search projects': '搜索项目',
    'Search projects...': '搜索项目…',
    'Show completed tasks': '显示已完成的任务',
    Name: '名称',
    Created: '创建日期',
    Tasks: '任务',
    Client: '客户',
    'Total Time': '累计工时',
    Progress: '进度',
    'Billable Rate': '计费费率',
    Status: '状态',
    Visibility: '可见性',
    Edit: '编辑',
    'No client': '无客户',
    Active: '进行中',
    Archived: '已归档',
    Public: '公开',
    Private: '私有',
    'No tasks': '暂无任务',
    Done: '已完成',
    'Loading schedule…': '正在加载排期…',
    'Could not load tasks.': '无法加载任务。',
    'Task / Planned dates': '任务 / 计划日期',
    Timer: '计时器',
    Today: '今天',
    'No dates set': '未设置日期',
    Ongoing: '持续进行',
    'Start date': '开始日期',
    'Pick a date': '选择日期',
    'Clear dates': '清除排期',
    Apply: '应用',
    'Ongoing · No due date': '持续进行 · 无截止日期',
    'Choose a start date before saving a due date.': '请先选择开始日期，再保存截止日期。',
    'Due date must be on or after the start date.': '截止日期不能早于开始日期。',
    'Date range': '日期范围',
    'Task dates': '任务日期',
    'Running tasks': '计时中的任务',
    'Log Out': '退出登录',
    Feedback: '反馈',
    'No active tasks': '暂无进行中的任务',
    Overdue: '已逾期',
    Scheduled: '已排期',
    Upcoming: '即将开始',
    Unscheduled: '未排期',
    Completed: '完成日期',
    'Due date': '截止日期',
    'Task Name': '任务名称',
    'No tasks found': '暂无任务',
    'Create Task': '新建任务',
    'No projects found': '暂无项目',
};

const preference = useStorage('solidtime-interface-language', 'en');
const language = computed(() => (preference.value === 'zh-CN' ? 'zh-CN' : 'en'));

watchEffect(() => {
    if (typeof document !== 'undefined') document.documentElement.lang = language.value;
});

export function useInterfaceLanguage() {
    function t(english: string) {
        return language.value === 'zh-CN' ? (zhCN[english] ?? english) : english;
    }

    function setLanguage(value: unknown) {
        if (value === 'en' || value === 'zh-CN') preference.value = value;
    }

    return { language, t, setLanguage };
}
