/**
 * 回忆录后台 · RequireJS / 资源路径配置
 *
 * 复刻 booking 的 config-admin.js，但去掉了对 window.CONFIG 的强依赖，
 * 让纯静态 HTML 页面也能直接复用同一套路径与 PATH_CONFIG（图标选择器需要）。
 *
 * BASE_URL 写死为 /static/（本后台静态资源根目录）。
 */
(function () {
    var BASE_URL = '/static/';
    window.BASE_URL = BASE_URL;

    require.config({
        urlArgs: "v=" + ((window.CONFIG && window.CONFIG.VERSION) ? window.CONFIG.VERSION : '1.0.0'),
        baseUrl: BASE_URL,
        paths: {
            "jquery": ["plugs/jquery-3.4.1/jquery-3.4.1.min"],
            "layuiall": ["plugs/layui-v2.5.6/layui.all"],
            "layui": ["plugs/layui-v2.5.6/layui"],
            "miniAdmin": ["plugs/lay-module/layuimini/miniAdmin"],
            "miniMenu": ["plugs/lay-module/layuimini/miniMenu"],
            "miniTab": ["plugs/lay-module/layuimini/miniTab"],
            "miniTheme": ["plugs/lay-module/layuimini/miniTheme"],
            "miniTongji": ["plugs/lay-module/layuimini/miniTongji"],
            "treetable": ["plugs/lay-module/treetable-lay/treetable"],
            "tableSelect": ["plugs/lay-module/tableSelect/tableSelect"],
            "iconPickerFa": ["plugs/lay-module/iconPicker/iconPickerFa"],
            "autocomplete": ["plugs/lay-module/autocomplete/autocomplete"],
            "echarts": ["plugs/echarts/echarts.min"],
            "echarts-theme": ["plugs/echarts/echarts-theme"]
        }
    });

    // 图标选择器需要从 font-awesome 的 less 变量文件里解析全部图标
    var PATH_CONFIG = {
        iconLess: BASE_URL + "plugs/font-awesome-4.7.0/less/variables.less"
    };
    window.PATH_CONFIG = PATH_CONFIG;
})();
