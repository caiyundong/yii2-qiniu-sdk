<?php
/**
 * 七牛云SDK
 * User: caiyundong
 * Date: 26/07/2018
 * Time: 14:58
 */
namespace caiyundong\Qiniu;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use Qiniu\Storage\BucketManager;
use Qiniu\Processing\ImageUrlBuilder;
use Qiniu\Processing\PersistentFop;
use Qiniu\Config;


class Qiniu extends Component
{
    const CODE_SUCCESS = 0; // 正常返回时使用"0"作为code
    const MESSAGE_SUCCESS = 'ok'; // 正常返回时使用"ok"作为message

    public $accessKey; // Access Key

    public $secretKey; // Secret Key

    public $domain; // 七牛域名

    public $bucket; // 使用的空间

    public $secure = false; // 是否使用HTTPS，默认不使用

    protected $auth;

    protected $config;

    protected $managers;

    public function init()
    {
        parent::init();
        if (!isset($this->accessKey)) {
            throw new InvalidConfigException('请先配置Access Key');
        }
        if (!isset($this->secretKey)) {
            throw new InvalidConfigException('请先配置Secret Key');
        }
        if (!isset($this->domain)) {
            throw new InvalidConfigException('请先配置您的七牛域名');
        }
        if (!isset($this->bucket)) {
            throw new InvalidConfigException('请先配置使用的Bucket');
        }
        $this->auth = new Auth($this->accessKey, $this->secretKey);
        $this->config = new Config();
        $this->managers = [];
    }

    /**
     * 使用文件内容上传
     * @param string $fileName 目标文件名
     * @param string $fileData 文件内容
     * @return mixed
     */
    public function put($fileName, $fileData)
    {
        if (!isset($this->managers['upload'])) {
            $this->managers['upload'] = new UploadManager();
        }
        $uploadToken = $this->auth->uploadToken($this->bucket);
        list($ret, $err) = $this->managers['upload']->put($uploadToken, $fileName, $fileData);
        // 正常情况
        if (is_null($err)) {
            return [
                'code' => self::CODE_SUCCESS,
                'message' => self::MESSAGE_SUCCESS,
                'result' => [
                    'hash' => $ret['hash'],
                    'key' => $ret['key'],
                    'url' => sprintf('%s%s/%s',
                        $this->secure ? 'https://' : 'http://',
                        rtrim($this->domain, '/'),
                        $fileName
                    ),
                ],
            ];
        }
        // 错误情况
        return [
            'code' => $err->code(),
            'message' => $err->message(),
            'result' => [
                'hash' => '',
                'key' => '',
                'url' => '',
            ],
        ];
    }

    /**
     * 使用文件路径上传
     * @param string $fileName 目标文件名
     * @param string $filePath 本地文件路径
     * @return mixed
     */
    public function putFile($fileName, $filePath)
    {
        if (!isset($this->managers['upload'])) {
            $this->managers['upload'] = new UploadManager();
        }
        $uploadToken = $this->auth->uploadToken($this->bucket);
        list($ret, $err) = $this->managers['upload']->putFile($uploadToken, $fileName, $filePath);

        // 正常情况
        if (is_null($err)) {
            return [
                'code' => self::CODE_SUCCESS,
                'message' => self::MESSAGE_SUCCESS,
                'result' => [
                    'hash' => $ret['hash'],
                    'key' => $ret['key'],
                    'url' => sprintf('%s%s/%s',
                        $this->secure ? 'https://' : 'http://',
                        rtrim($this->domain, '/'),
                        $fileName
                    ),
                ],
            ];
        }
        // 错误情况
        return [
            'code' => $err->code(),
            'message' => $err->message(),
            'result' => [
                'hash' => '',
                'key' => '',
                'url' => '',
            ],
        ];
    }

    /**
     * 批量生成私有文件下载链接，并直接下载到本地路径
     * @param array $fileNameList 私有文件链接
     * @param bool|false $realDownload 是否直接下载
     * @param string $downloadPath 下载文件保存路径
     * @return array 下载文件链接列表，key为私有文件链接，value为临时下载链接
     */
    public function batchDownload($fileNameList = [], $realDownload = false, $downloadPath = '')
    {
        if (empty($fileNameList)) {
            return [];
        }
        $result = [];
        foreach ($fileNameList as $fileName) {
            $result[$fileName] = $this->auth->privateDownloadUrl($fileName);
        }
        // 仅返回下载链接
        if (!$realDownload) {
            return $result;
        }
        // 下载文件
        if (trim($downloadPath) === '') {
            $downloadPath = __DIR__;
        }
        // 创建目录
        if (!is_dir($downloadPath)) {
            mkdir($downloadPath, 0777, true);
        }
        foreach ($result as $fileName => $url) {
            $name = substr($fileName, strrpos($fileName, '/') + 1);
            file_put_contents($downloadPath.'/'.$name, file_get_contents($url));
        }
        return $result;
    }

    /**
     * 获取上传凭证
     * @param string|null $bucket
     * @param string|null $key
     * @param int $expires
     * @param array|null $policy
     * @return mixed
     */
    public function uploadToken(
        $bucket = null,
        $key = null,
        $expires = 3600,
        $policy = null)
    {
        // 默认使用当前配置的bucket
        if ($bucket === null) {
            $bucket = $this->bucket;
        }
        return $this->auth->uploadToken(
            $bucket,
            $key,
            $expires,
            $policy,
            true
        );
    }

    //-------------------------------------
    // Bucket Management
    //-------------------------------------
    /**
     * 获取指定账号下所有的空间名。
     *
     * @return string[] 包含所有空间名
     */
    public function buckets($shared=true)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->buckets($shared);
    }

    /**
     * 获取指定空间绑定的所有的域名
     * @param $bucket
     *
     * @return string[] 包含所有空间域名
     */
    public function domains($bucket)
    {
        if (!isset($this->managers['bucket']))
        {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->domains($bucket);
    }

    /**
     * 列取空间的文件列表
     *
     * @param $bucket     空间名
     * @param $prefix     列举前缀
     * @param $marker     列举标识符
     * @param $limit      单次列举个数限制
     * @param $delimiter  指定目录分隔符
     *
     * @return array    包含文件信息的数组，类似：[
     *                                              {
     *                                                 "hash" => "<Hash string>",
     *                                                  "key" => "<Key string>",
     *                                                  "fsize" => "<file size>",
     *                                                  "putTime" => "<file modify time>"
     *                                              },
     *                                              ...
     *                                            ]
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/list.html
     */
    public function listFiles($bucket, $prefix = null, $marker = null, $limit = 1000, $delimiter = null)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->listFiles($bucket, $prefix, $marker, $limit, $delimiter);
    }

    /**
     * 获取资源的元信息，但不返回文件内容
     *
     * @param $bucket     待获取信息资源所在的空间
     * @param $key        待获取资源的文件名
     *
     * @return array    包含文件信息的数组，类似：
     *                                              [
     *                                                  "hash" => "<Hash string>",
     *                                                  "key" => "<Key string>",
     *                                                  "fsize" => <file size>,
     *                                                  "putTime" => "<file modify time>"
     *                                                  "fileType" => <file type>
     *                                              ]
     *
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/stat.html
     */
    public function stat($bucket, $key)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->stat($bucket, $key);
    }

    /**
     * 删除指定资源
     *
     * @param $bucket     待删除资源所在的空间
     * @param $key        待删除资源的文件名
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/delete.html
     */
    public function delete(
        $bucket = null,
        $key = null)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        $err = $this->managers['bucket']->delete($bucket, $key);

        // 正常情况
        if (is_null($err)) {
            return [
                'code' => self::CODE_SUCCESS,
                'message' => self::MESSAGE_SUCCESS
            ];
        }
        else
            return [
                'code' => $err->code(),
                'message' => $err->message()
            ];
    }

    /**
     * 给资源进行重命名，本质为move操作。
     *
     * @param $bucket     待操作资源所在空间
     * @param $oldname    待操作资源文件名
     * @param $newname    目标资源文件名
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     */
    public function rename($bucket, $oldname, $newname)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->rename($bucket, $oldname, $newname);
    }

    /**
     * 给资源进行重命名，本质为move操作。
     *
     * @param $from_bucket     待操作资源所在空间
     * @param $from_key        待操作资源文件名
     * @param $to_bucket       目标资源空间名
     * @param $to_key          目标资源文件名
     * @param $force
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/copy.html
     */
    public function copy($from_bucket, $from_key, $to_bucket, $to_key, $force = false)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->copy($from_bucket, $from_key, $to_bucket, $to_key, $force);
    }

    /**
     * 将资源从一个空间到另一个空间
     *
     * @param $from_bucket     待操作资源所在空间
     * @param $from_key        待操作资源文件名
     * @param $to_bucket       目标资源空间名
     * @param $to_key          目标资源文件名
     * @param $force
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/move.html
     */
    public function move($from_bucket, $from_key, $to_bucket, $to_key, $force = false) {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->move($from_bucket, $from_key, $to_bucket, $to_key, $force);
    }

    /**
     * 主动修改指定资源的文件类型
     *
     * @param $bucket     待操作资源所在空间
     * @param $key        待操作资源文件名
     * @param $mime       待操作文件目标mimeType
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/chgm.html
     */
    public function changeMime($bucket, $key, $mime)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->changeMime($bucket, $key, $mime);
    }

    /**
     * 修改指定资源的存储类型
     *
     * @param $bucket     待操作资源所在空间
     * @param $key        待操作资源文件名
     * @param $fileType       待操作文件目标文件类型
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  https://developer.qiniu.com/kodo/api/3710/modify-the-file-type
     */
    public function changeType($bucket, $key, $fileType)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->changeType($bucket, $key, $fileType);
    }

    /**
     * 修改文件的存储状态，即禁用状态和启用状态间的的互相转换
     *
     * @param $bucket     待操作资源所在空间
     * @param $key        待操作资源文件名
     * @param $status       待操作文件目标文件类型
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  https://developer.qiniu.com/kodo/api/4173/modify-the-file-status
     */
    public function changeStatus($bucket, $key, $status)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->changeStatus($bucket, $key, $status);
    }

    /**
     * 从指定URL抓取资源，并将该资源存储到指定空间中
     *
     * @param $url        指定的URL
     * @param $bucket     目标资源空间
     * @param $key        目标资源文件名
     *
     * @return array    包含已拉取的文件信息。
     *                         成功时：  [
     *                                          [
     *                                              "hash" => "<Hash string>",
     *                                              "key" => "<Key string>"
     *                                          ],
     *                                          null
     *                                  ]
     *
     *                         失败时：  [
     *                                          null,
     *                                         Qiniu/Http/Error
     *                                  ]
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/fetch.html
     */
    public function fetch($url, $bucket, $key = null)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->fetch($url, $bucket, $key);
    }

    /**
     * 从镜像源站抓取资源到空间中，如果空间中已经存在，则覆盖该资源
     *
     * @param $bucket     待获取资源所在的空间
     * @param $key        代获取资源文件名
     *
     * @return mixed      成功返回NULL，失败返回对象Qiniu\Http\Error
     * @link  http://developer.qiniu.com/docs/v6/api/reference/rs/prefetch.html
     */
    public function prefetch($bucket, $key)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->prefetch($bucket, $key);
    }

    /**
     * 在单次请求中进行多个资源管理操作
     *
     * @param $operations     资源管理操作数组
     *
     * @return array 每个资源的处理情况，结果类似：
     *              [
     *                   { "code" => <HttpCode int>, "data" => <Data> },
     *                   { "code" => <HttpCode int> },
     *                   { "code" => <HttpCode int> },
     *                   { "code" => <HttpCode int> },
     *                   { "code" => <HttpCode int>, "data" => { "error": "<ErrorMessage string>" } },
     *                   ...
     *               ]
     * @link http://developer.qiniu.com/docs/v6/api/reference/rs/batch.html
     */
    public function batch($operations)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->batch($operations);
    }

    /**
     * 设置文件的生命周期
     *
     * @param $bucket 设置文件生命周期文件所在的空间
     * @param $key    设置文件生命周期文件的文件名
     * @param $days   设置该文件多少天后删除，当$days设置为0时表示取消该文件的生命周期
     *
     * @return Mixed
     * @link https://developer.qiniu.com/kodo/api/update-file-lifecycle
     */
    public function deleteAfterDays($bucket, $key, $days)
    {
        if (!isset($this->managers['bucket'])) {
            $this->managers['bucket'] = new BucketManager($this->auth);
        }
        return $this->managers['bucket']->deleteAfterDays($bucket, $key, $days);
    }

    /**
     * 缩略图链接拼接
     *
     * @param  string $url 图片链接
     * @param  int $mode 缩略模式
     * @param  int $width 宽度
     * @param  int $height 长度
     * @param  string $format 输出类型
     * @param  int $quality 图片质量
     * @param  int $interlace 是否支持渐进显示
     * @param  int $ignoreError 忽略结果
     * @return string
     * @link http://developer.qiniu.com/code/v6/api/kodo-api/image/imageview2.html
     * @author Sherlock Ren <sherlock_ren@icloud.com>
     */
    public function thumbnail(
        $url,
        $mode,
        $width,
        $height,
        $format = null,
        $interlace = null,
        $quality = null,
        $ignoreError = 1
    )
    {
        $imageUrlBuilder = new ImageUrlBuilder();
        return $imageUrlBuilder->thumbnail(
            $url,
            $mode,
            $width,
            $height,
            $format,
            $interlace,
            $quality,
            $ignoreError
        );
    }

    /**
     * 图片水印
     *
     * @param  string $url 图片链接
     * @param  string $image 水印图片链接
     * @param  numeric $dissolve 透明度
     * @param  string $gravity 水印位置
     * @param  numeric $dx 横轴边距
     * @param  numeric $dy 纵轴边距
     * @param  numeric $watermarkScale 自适应原图的短边比例
     * @link   http://developer.qiniu.com/code/v6/api/kodo-api/image/watermark.html
     * @return string
     * @author Sherlock Ren <sherlock_ren@icloud.com>
     */
    public function waterImg(
        $url,
        $image,
        $dissolve = 100,
        $gravity = 'SouthEast',
        $dx = null,
        $dy = null,
        $watermarkScale = null
    )
    {
        $imageUrlBuilder = new ImageUrlBuilder();
        return $imageUrlBuilder->waterImg(
            $url,
            $image,
            $dissolve,
            $gravity,
            $dx,
            $dy,
            $watermarkScale
        );
    }

    /**
     * 文字水印
     *
     * @param  string $url 图片链接
     * @param  string $text 文字
     * @param  string $font 文字字体
     * @param  string $fontSize 文字字号
     * @param  string $fontColor 文字颜色
     * @param  numeric $dissolve 透明度
     * @param  string $gravity 水印位置
     * @param  numeric $dx 横轴边距
     * @param  numeric $dy 纵轴边距
     * @link   http://developer.qiniu.com/code/v6/api/kodo-api/image/watermark.html#text-watermark
     * @return string
     * @author Sherlock Ren <sherlock_ren@icloud.com>
     */
    public function waterText(
        $url,
        $text,
        $font = '黑体',
        $fontSize = 0,
        $fontColor = null,
        $dissolve = 100,
        $gravity = 'SouthEast',
        $dx = null,
        $dy = null
    ) {
        $imageUrlBuilder = new ImageUrlBuilder();
        return $imageUrlBuilder->waterText(
            $url,
            $text,
            $font,
            $fontSize,
            $fontColor,
            $dissolve,
            $gravity,
            $dx,
            $dy
        );
    }

    // add a watermark to a mp4 video in the bucket
    public function waterVideo(
        $bucket,
        $key,                               //test.mp4
        $watermark_url,
        $pipeline,                          //neihanduanzi
        $wmGravity = "SouthEast",           //NorthWest, North, NorthEast, West, Center, East, SouthWest, South, SouthEast
        $wmOffsetX = 0,
        $wmOffsetY = 0,
        $notifyUrl = ""
    )
    {
        $force = false;
        $keys = explode(".", $key);
        //转码完成后通知到你的业务服务器。
        $pfop = new PersistentFop(self.auth, self.config);
        //要进行转码的转码操作。 http://developer.qiniu.com/docs/v6/api/reference/fop/av/avthumb.html
        //$fops = "avthumb/mp4/s/640x360/vb/1.4m|saveas/" . \Qiniu\base64_urlSafeEncode($bucket . ":qiniu_640x360.mp4");
        $fops = "avthumb/mp4/wmImage/$watermark_url/wmGravity/$wmGravity/wmOffsetX/$wmOffsetX/wmOffsetY/$wmOffsetY|saveas/" . \Qiniu\base64_urlSafeEncode($bucket . ":{$keys[0]}_w.mp4");
        list($id, $err) = $pfop->execute($bucket, $key, $fops, $pipeline, $notifyUrl, $force);
        echo "\n====> pfop avthumb result: \n";
        if ($err != null) {
            var_dump($err);
        } else {
            echo "PersistentFop Id: $id\n";
        }
        //查询转码的进度和状态
        list($ret, $err) = $pfop->status($id);
        echo "\n====> pfop avthumb status: \n";
        if ($err != null) {
            var_dump($err);
        } else {
            var_dump($ret);
        }
    }
}