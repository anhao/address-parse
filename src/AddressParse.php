<?php

declare(strict_types=1);

/*
 * This file is part of the anhao/address-parse.
 * (c) Alone88 <im@alone88.cn>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace ALAPI;

class AddressParse
{
    private string $address;

    private string $cities;

    private string $provinces;

    private string $areas;

    private string $names;

    /**
     * 解析方式,1为正则，2为树查找.
     */
    private int $type;

    /**
     * 过滤字词.
     */
    private array $textFilter;

    /**
     * 名字最大长度，默认 4.
     */
    private int $nameMaxLength = 4;

    /**
     * AddressParse constructor.
     *
     * @param int $type 解析类型
     */
    public function __construct(int $type = 1, int $nameMaxLength = 4, array $textFilter = [])
    {
        $this->type = $type;
        $this->textFilter = $textFilter;
        $this->nameMaxLength = $nameMaxLength;
        $this->provinces = $this->readFileContent(__DIR__ . '/../data/provinces.json');
        $this->cities = $this->readFileContent(__DIR__ . '/../data/cities.json');
        $this->areas = $this->readFileContent(__DIR__ . '/../data/areas.json');
        $this->names = $this->readFileContent(__DIR__ . '/../data/names.json');
    }

    /**
     * 解析收货地址
     *
     * @param $address
     *
     * @return array
     */
    public function parse(string $address): array
    {
        $this->address = $address;
        $parseResult = [
            'phone' => '',
            'province' => [],
            'city' => [],
            'area' => [],
            'detail' => [],
            'name' => '',
            'postalCode' => '',
        ];
        $this->cleanAddress();
        $parseResult['phone'] = $this->parsePhone();
        $parseResult['postalCode'] = $this->parsePostalCode();
        $splitAddress = explode(' ', $this->address);
        $splitAddress = array_filter(array_map(function ($item) {
            return trim($item);
        }, $splitAddress));
        foreach ($splitAddress as $value) {
            if (!$parseResult['province'] || !$parseResult['city'] || !$parseResult['area']) {
                $parse = 1 === $this->type ? $this->parseRegionWithRegexp($value, $parseResult) : $this->parseRegion($value, $parseResult);
                $parseResult['province'] = $parse['province'] ?? [];
                $parseResult['city'] = $parse['city'] ?? [];
                $parseResult['area'] = $parse['area'] ?? [];
                $parseResult['detail'] = array_merge($parseResult['detail'], $parse['detail']);
            } else {
                $parseResult['detail'][] = $value;
            }
        }
        $province = reset($parseResult['province']);
        $city = reset($parseResult['city']);
        $area = reset($parseResult['area']);
        $detail = $parseResult['detail'];
        foreach ($detail as $key => $value) {
            $detail[$key] = str_replace([$province['name'] ?? '', $city['name'] ?? '', $area['name'] ?? ''], '', $value);
        }
        $detail = array_filter(array_unique($detail));
        if ($detail && \count($detail) > 0) {
            $copyDetail = array_filter($detail, function ($item) {
                return (bool)$item;
            });
            sort($copyDetail);
            $name = '';
            foreach ($copyDetail as $key => $value) {
                $result = $this->parseName($value);
                $index = $this->array_search_index($result, $copyDetail);
                if ($index) {
                    $name = $copyDetail[$index];
                } elseif (mb_strlen($copyDetail[0]) <= 4 && preg_match('/[\\x{4e00}-\\x{9fa5}]/ui', $copyDetail[0])) {
                    $name = $copyDetail[0];
                }
            }
            if ($name) {
                $parseResult['name'] = $name;
                $detail = array_unique(array_filter($detail, function ($item) use ($name) {
                    return $item !== $name;
                }));
            }
        }
        $provinceName = $province['name'] ?? '';
        $provinceCode = $province['code'] ?? '';
        $cityName = $city['name'] ?? '';
        $cityCode = $city['code'] ?? '';
        if (\in_array($cityName, ['市辖区', '区', '县', '镇'], true)) {
            $cityName = $provinceName;
        }

        return [
            'province' => $provinceName,
            'provinceCode' => $provinceCode,
            'city' => $cityName,
            'cityCode' => $cityCode,
            'area' => $area['name'] ?? '',
            'areaCode' => $area['code'] ?? '',
            'detail' => implode('', $detail),
            'phone' => $parseResult['phone'],
            'postalCode' => $parseResult['postalCode'],
            'name' => $parseResult['name'],
        ];
    }

    /**
     * 设置名字最大长度.
     *
     * @return $this
     */
    public function setNameMaxLength(int $nameMaxLength): self
    {
        $this->nameMaxLength = $nameMaxLength;

        return $this;
    }

    /**
     * 解析方式，1:正则，2:树查找.
     *
     * @param int $type 解析方式
     *
     * @return $this
     */
    public function setType(int $type = 1): self
    {
        $types = [1, 2];
        $this->type = \in_array($type, $types, true) ? $type : 1;

        return $this;
    }

    /**
     * 设置过滤词.
     */
    public function setTextFilter(array $textFilter = []): self
    {
        $this->textFilter = $textFilter;

        return $this;
    }

    /**
     * 通过正则解析地址
     *
     * @param $fragment
     * @param $hasParseResult
     *
     * @return array
     */
    private function parseRegionWithRegexp($fragment, $hasParseResult)
    {
        $province = $hasParseResult['province'] ?? [];
        $city = $hasParseResult['city'] ?? [];
        $area = $hasParseResult['area'] ?? [];
        $detail = [];
        $matchStr = '';

        if (0 === \count($province)) {
            $fragmentArray = mb_str_split($fragment);
            for ($i = 1; $i < \count($fragmentArray); ++$i) {
                $str = mb_substr($fragment, 0, $i + 1);
                $reg = "/{\"code\":\"[0-9]{1,16}\",\"name\":\"{$str}[\\x{4e00}-\\x{9fa5}]*?\"}/u";
                if (preg_match($reg, $this->provinces, $m)) {
                    $result = json_decode($m[0], true);
                    $province = [];
                    $matchStr = $str;
                    $province[] = $result;
                } else {
                    break;
                }
            }
            if ($province) {
                $fragment = str_replace($matchStr, '', $fragment);
            }
        }

        if (0 === \count($city)) {
            $fragmentArray = mb_str_split($fragment);
            for ($i = 1; $i < \count($fragmentArray); ++$i) {
                $str = mb_substr($fragment, 0, $i + 1);
                $code = $province[0]['code'] ?? '[0-9]{1,6}';
                $reg = "/{\"code\":\"[0-9]{1,6}\",\"name\":\"{$str}[\\x{4e00}-\\x{9fa5}]*?\",\"provinceCode\":\"{$code}\"}/u";
                if (preg_match($reg, $this->cities, $m)) {
                    $city = [];
                    $matchStr = $str;
                    $city[] = json_decode($m[0], true);
                }
            }
            if ($city) {
                $provinceCode = $province[0]['code'] ?? $city[0]['provinceCode'];
                $fragment = str_replace($matchStr, '', $fragment);
                if (0 === \count($province)) {
                    $reg = "/{\"code\":\"{$provinceCode}\",\"name\":\"[\\x{4e00}-\\x{9fa5}]+?\"}/u";
                    if (preg_match($reg, $this->provinces, $m)) {
                        $province[] = json_decode($m[0], true);
                    }
                }
            }
        }

        if (0 === \count($area)) {
            $fragmentArray = mb_str_split($fragment);
            for ($i = 1; $i < \count($fragmentArray); ++$i) {
                $str = mb_substr($fragment, 0, $i + 1);
                $provinceCode = $province[0]['code'] ?? '[0-9]{1,6}';
                $cityCode = $city[0]['code'] ?? '[0-9]{1,6}';
                $reg = "/{\"code\":\"[0-9]{1,9}\",\"name\":\"{$str}[\\x{4e00}-\\x{9fa5}]*?\",\"cityCode\":\"{$cityCode}\",\"provinceCode\":\"{$provinceCode}\"}/u";
                if (preg_match($reg, $this->areas, $m)) {
                    $area = [];
                    $matchStr = $str;
                    $area[] = json_decode($m[0], true);
                }
            }
            if ($area) {
                $provinceCode = $province[0]['code'] ?? $area[0]['provinceCode'];
                $cityCode = $city[0]['code'] ?? $area[0]['cityCode'];
                $fragment = str_replace($matchStr, '', $fragment);
                if (0 === \count($province)) {
                    $reg = "/{\"code\":\"{$provinceCode}\",\"name\":\"[\\x{4e00}-\\x{9fa5}]+?\"}/u";
                    if (preg_match($reg, $this->provinces, $m)) {
                        $province[] = json_decode($m[0], true);
                    }
                }
                if (0 === \count($city)) {
                    $reg = "/{\"code\":\"{$cityCode}\",\"name\":\"[\\x{4e00}-\\x{9fa5}]*?\",\"provinceCode\":\"{$provinceCode}\"}/u";
                    if (preg_match($reg, $this->cities, $m)) {
                        $city[] = json_decode($m[0], true);
                    }
                }
            }
        }
        if (mb_strlen($fragment) > 0) {
            $detail[] = $fragment;
        }

        return [
            'province' => $province,
            'city' => $city,
            'area' => $area,
            'detail' => $detail,
        ];
    }

    /**
     * 树向下查找.
     *
     * @param $fragment
     * @param $hasParseResult
     *
     * @return array
     */
    private function parseRegion($fragment, $hasParseResult): array
    {
        $province = [];
        $city = [];
        $area = [];
        $detail = [];
        $provinces = json_decode($this->provinces, true);
        $cities = json_decode($this->cities, true);
        $areas = json_decode($this->areas, true);

        //省份
        if ($hasParseResult['province']) {
            $province = $hasParseResult['province'];
        } else {
            foreach ($provinces as $tempProvince) {
                $name = $tempProvince['name'];
                $replaceName = '';
                $nameArr = mb_str_split($name);
                for ($i = \count($nameArr); $i > 1; --$i) {
                    $temp = mb_substr($name, 0, $i);
                    if (0 === mb_strpos($fragment, $temp)) {
                        $replaceName = $temp;

                        break;
                    }
                }
                if ($replaceName) {
                    $province[] = $tempProvince;
                    $fragment = str_replace($replaceName, '', $fragment);

                    break;
                }
            }
        }
        //城市
        if ($hasParseResult['city']) {
            $city = $hasParseResult['city'];
        } else {
            foreach ($cities as $tempCity) {
                $name = $tempCity['name'];
                $nameArr = mb_str_split($name);
                $provinceCode = $tempCity['provinceCode'];
                $currentProvince = $province[0] ?? [];
                if ($currentProvince) {
                    if ($currentProvince['code'] === $provinceCode) {
                        $replaceName = '';
                        for ($i = \count($nameArr); $i > 1; --$i) {
                            $temp = mb_substr($name, 0, $i);
                            if (0 === mb_strpos($fragment, $temp)) {
                                $replaceName = $temp;

                                break;
                            }
                        }
                        if ($replaceName) {
                            $city[] = $tempCity;
                            $fragment = str_replace($replaceName, '', $fragment);

                            break;
                        }
                    }
                } else {
                    //当前没有省
                    for ($i = \count($nameArr); $i > 1; --$i) {
                        $replaceName = mb_substr($name, 0, $i);
                        if (0 === mb_strpos($fragment, $replaceName)) {
                            $city[] = $tempCity;
                            $province[] = $this->array_search_filter('code', $provinceCode, $provinces);
                            $fragment = str_replace($replaceName, '', $fragment);
                        }
                    }
                    if (\count($city) > 0) {
                        break;
                    }
                }
            }
        }
        foreach ($areas as $tempAreas) {
            $name = $tempAreas['name'];
            $nameArr = mb_str_split($name);
            $provinceCode = $tempAreas['provinceCode'];
            $cityCode = $tempAreas['cityCode'];
            $currentProvince = $province[0] ?? [];
            $currentCity = $city[0] ?? [];
            // 有省或者市
            if ($currentProvince || $currentCity) {
                if (($currentProvince && $currentProvince['code'] === $provinceCode)
                    || $currentCity && $currentCity['code'] === $cityCode
                ) {
                    $replaceName = '';
                    for ($i = \count($nameArr); $i > 1; --$i) {
                        $temp = mb_substr($name, 0, $i);
                        if (0 === mb_strpos($fragment, $temp)) {
                            $replaceName = $temp;

                            break;
                        }
                    }
                    if ($replaceName) {
                        $area[] = $tempAreas;
                        !$currentCity && array_push($city, $this->array_search_filter('code', $cityCode, $cities));
                        !$currentProvince && array_push($province, $this->array_search_filter('code', $provinceCode, $provinces));
                        $fragment = str_replace($replaceName, '', $fragment);

                        break;
                    }
                }
            } else {
                for ($i = \count($nameArr); $i > 1; --$i) {
                    $replaceName = mb_substr($name, 0, $i);
                    if (0 === mb_strpos($fragment, $replaceName)) {
                        $area[] = $tempAreas;
                        $city[] = $this->array_search_filter('code', $cityCode, $cities);
                        $province[] = $this->array_search_filter('code', $provinceCode, $provinces);
                        $fragment = str_replace($replaceName, '', $fragment);

                        break;
                    }
                }
                if (\count($area) > 0) {
                    break;
                }
            }
        }
        if (mb_strlen($fragment)) {
            $detail[] = $fragment;
        }

        return [
            'province' => $province,
            'city' => $city,
            'area' => $area,
            'detail' => $detail,
        ];
    }

    /**
     * 地址清洗.
     *
     * @return string|string[]|null
     */
    private function cleanAddress()
    {
        $this->address = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $this->address);
        $search = [
            '详细地址',
            '收货地址',
            '收件地址',
            '地址',
            '所在地区',
            '地区',
            '姓名',
            '收货人',
            '收件人',
            '联系人',
            '收',
            '邮编',
            '联系电话',
            '电话',
            '联系人手机号码',
            '手机号码',
            '手机号',
            '自治区直辖县级行政区划',
            '省直辖县级行政区划',
        ];
        $search = array_merge($search, $this->textFilter);
        foreach ($search as $key => $value) {
            $this->address = str_replace($value, ' ', $this->address);
        }
        $reg = "/[`~!@#$^&*()=|{}':;',\\[\\].<>\\/?~！@#￥……&*（）——|{}【】‘；：”“’。，、？]/u";
        $this->address = (string)preg_replace($reg, ' ', $this->address);
        $this->address = (string)preg_replace('/\s{2,}/u', ' ', $this->address);

        return $this->address;
    }

    /**
     * 解析姓名.
     *
     * @param $fragment
     *
     * @return string
     */
    private function parseName($fragment)
    {
        $names = json_decode($this->names, true);

        if (empty($fragment) || preg_match('/[\\x4E00-\\x9FA5]/', $fragment)) {
            return '';
        }
        // 如果包含下列称呼，则认为是名字，可自行添加
        $nameCall = ['先生', '小姐', '同志', '哥哥', '姐姐', '妹妹', '弟弟', '妈妈', '爸爸', '爷爷', '奶奶', '姑姑', '舅舅'];
        if (\in_array($fragment, $nameCall, true)) {
            return $fragment;
        }
        $filters = ['街道', '乡镇','镇','乡'];
        if (\in_array($fragment, $filters, true)) {
            return '';
        }
        $nameFirst = mb_substr($fragment, 0, 1);
        $nameLen = mb_strlen($fragment);
        if ($nameLen <= $this->nameMaxLength && $nameLen > 1 && \in_array($nameFirst, $names, true)) {
            return $fragment;
        }

        return '';
    }

    /**
     * 解析手机号.
     *
     * @return array
     */
    private function parsePhone()
    {
        $this->address = (string)preg_replace('/(\\d{3})-(\\d{4})-(\\d{4})/u', '$1$2$3', $this->address);
        $this->address = (string)preg_replace('/(\\d{3}) (\\d{4}) (\\d{4})/u', '$1$2$3', $this->address);
        $this->address = (string)preg_replace('/(\\d{4}) \\d{4} \\d{4}/u', '$1$2$3', $this->address);
        $this->address = (string)preg_replace('/(\\d{4})/u', '$1$2$3', $this->address);
        $phoneReg = '/(\\d{7,12})|(\\d{3,4}-\\d{6,8})|(86-[1][0-9]{10})|(86[1][0-9]{10})|([1][0-9]{10})/u';
        preg_match($phoneReg, $this->address, $m);
        $phone = '';
        if (\count($m) > 0) {
            $phone = $m[0];
            $this->address = str_replace($m[0], ' ', $this->address);
        }

        return $phone;
    }

    /**
     * 解析邮政编码
     */
    private function parsePostalCode(): string
    {
        $postalCode = '';
        $postalCodeReg = '/\\d{6}/U';
        preg_match($postalCodeReg, $this->address, $m);
        if (\count($m) > 0) {
            $postalCode = $m[0];
            $this->address = str_replace($m[0], ' ', $this->address);
        }

        return $postalCode;
    }

    /**
     * 读取文件.
     *
     * @param $filename
     *
     * @return false|string
     */
    private function readFileContent($filename)
    {
        $fp = fopen($filename, 'r');
        $content = fread($fp, filesize($filename));
        fclose($fp);

        return $content;
    }

    /**
     * 二维数组查找值
     *
     * @param $key
     * @param $value
     * @param $array
     *
     * @return mixed
     */
    private function array_search_filter($key, $value, $array)
    {
        $array = array_filter($array, function ($item) use ($key, $value) {
            return $item[$key] === $value;
        });

        return reset($array);
    }

    /**
     * 数组查找 index.
     *
     * @param $node
     * @param $array
     *
     * @return bool|mixed
     */
    private function array_search_index($node, $array)
    {
        $flip_array = array_flip($array);

        return isset($flip_array[$node]) ? $flip_array[$node] : false;
    }
}
