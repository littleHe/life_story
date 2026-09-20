/**
 * 回忆录后台 · 前端公共脚本
 * 依赖：layui.all.js（提供全局 layui / layui.$ / layui.layer 等）、jquery
 *
 * 统一封装：
 *   - AdminAPI.get / AdminAPI.post  —— 带凭证的 JSON 请求，自动处理 401 跳转登录
 *   - AdminAPI.table                —— 包装 layui.table.render（后端返回 {code:0,count,data} 直接可用）
 *   - AdminAPI.reload / del / open  —— 表格刷新 / 确认删除 / 弹窗
 */
var AdminAPI = (function () {
    function $() { return (window.layui && layui.$) ? layui.$ : window.$; }
    function layer() { return (window.layui && layui.layer) ? layui.layer : window.layer; }

    function request(method, url, data, success, fail) {
        // 注意：$ 与 layer 都是「取值函数」，必须调用后再访问成员（$().ajax / layer().msg）。
        // 写成 $.ajax 会访问函数对象自身的属性，恒为 undefined → 所有后台写操作静默失败。
        return $().ajax({
            url: url,
            type: method,
            data: data,
            dataType: 'json',
            xhrFields: { withCredentials: true },
            success: function (res) {
                if (res && res.code === 0) {
                    if (success) success(res);
                } else {
                    (fail || function (r) { layer().msg((r && r.msg) || '操作失败'); })(res);
                }
            },
            error: function (xhr) {
                if (xhr.status === 401) {
                    layer().msg('登录已失效，正在跳转登录页...');
                    setTimeout(function () { top.location.href = '/admin/login.html'; }, 800);
                    return;
                }
                layer().msg('网络错误(' + xhr.status + ')');
            }
        });
    }

    return {
        get: function (u, d, s, f) { return request('GET', u, d, s, f); },
        post: function (u, d, s, f) { return request('POST', u, d, s, f); },

        /** 渲染 layui 表格（后端返回格式 {code:0, msg, count, data}） */
        table: function (opts) {
            var table = layui.table;
            return table.render({
                elem: opts.elem,
                url: opts.url,
                method: 'GET',
                where: opts.where || {},
                page: true,
                limits: [10, 20, 50, 100],
                limit: 10,
                cols: opts.cols,
                toolbar: opts.toolbar || '',
                defaultToolbar: opts.defaultToolbar || ['filter', 'exports', 'print'],
                done: opts.done || function () {}
            });
        },

        /** 重载表格（带新的搜索条件） */
        reload: function (elem, where) {
            layui.table.reload(elem.replace('#', ''), { where: where, page: { curr: 1 } });
        },

        /** 确认后 POST 删除 */
        del: function (url, data, cb) {
            layer().confirm('确定执行该操作？', { title: '提示' }, function (index) {
                layer().close(index);
                AdminAPI.post(url, data, function (res) {
                    layer().msg(res.msg || '操作成功');
                    if (cb) cb(res);
                });
            });
        },

        /** 打开表单弹窗 */
        open: function (title, width, height, content, success) {
            return layer().open({
                type: 1,
                title: title,
                area: [width || '520px', height || 'auto'],
                content: content,
                success: success
            });
        }
    };
})();
