# 收货地址智能解析
收货地址智能解析，根据收货地址，智能解析出省市区，详情地址,以及收货人姓名，电话，邮政编码

## 安装 
```
composer require alapi/address_parse
```


## 使用
```php
use ALAPI\AddressParse;

require_once 'vendor/autoload.php';

$parse = new AddressParse();

$address = "北京 北京市 顺义区 胜利街道宜宾南区2-2-401 李俊南 18210997754";

print_r($parse->setType(1)->parse($address));
```

输出
```
Array
(
    [province] => 北京市
    [provinceCode] => 11
    [city] => 北京市
    [cityCode] => 1101
    [area] => 顺义区
    [areaCode] => 110113
    [detail] => 胜利街道宜宾南区2-2-401
    [phone] => 18210997754
    [postalCode] => 
    [name] => 李俊南
)
```

设置不同的解析方式，支持正则解析和树查找解析
```php
use ALAPI\AddressParse;

$parse = new AddressParse();

$address = "北京 北京市 顺义区 胜利街道宜宾南区2-2-401 李俊南 18210997754";

$parse->setType(1)->parse($address); #正则解析，type 为 1

$parse->setType(2)->parse($address); #树查找解析， type 为2

```

## ALAPI
[ALAPI](https://www.alapi.cn]) ,为开发者提供各种 API 开发支持

## 感谢
该组件参考 [zh-address-parse](https://github.com/ldwonday/zh-address-parse) 思路而来

## License
MIT