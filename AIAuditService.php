<?php
/**
 * AI审核服务 - 支持多平台
 * 
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class CommentAI_AIAuditService
{
    private $config;
    private $provider;
    private $apiKey;
    private $apiEndpoint;
    private $modelName;
    private $threshold;

    public function __construct($config)
    {
        $this->config = $config;
        $this->provider = $config->auditProvider;
        $this->apiKey = $config->auditApiKey ?: $config->apiKey;
        $this->modelName = $config->auditModelName ?: $config->modelName;
        $this->threshold = floatval($config->auditThreshold ?: 0.8);
        
        // 设置API端点
        $this->apiEndpoint = $this->getApiEndpoint();
    }

    /**
     * 获取API端点
     */
    private function getApiEndpoint()
    {
        // 如果用户自定义了端点，使用自定义的
        if (!empty($this->config->auditApiEndpoint)) {
            return rtrim($this->config->auditApiEndpoint, '/');
        }

        // 如果审核API端点未设置，使用回复API端点
        if (!empty($this->config->apiEndpoint)) {
            return rtrim($this->config->apiEndpoint, '/');
        }

        // 根据提供商返回默认端点
        switch ($this->provider) {
            case 'aliyun':
                return 'https://dashscope.aliyuncs.com/compatible-mode/v1';
            case 'openai':
                return 'https://api.openai.com/v1';
            case 'deepseek':
                return 'https://api.deepseek.com/v1';
            case 'kimi':
                return 'https://api.moonshot.cn/v1';
            case 'dmxapi':
                return 'https://www.dmxapi.cn/v1';
            case 'siliconflow':
                return 'https://api.siliconflow.cn/v1';
            case 'custom':
                throw new Exception('使用自定义接口时必须填写API地址');
            default:
                throw new Exception('未知的AI服务提供商: ' . $this->provider);
        }
    }

    /**
     * 审核评论内容
     * 
     * @param string $commentText 评论内容
     * @return array ['passed' => bool, 'confidence' => float, 'reason' => string]
     */
    public function auditComment($commentText)
    {
        // 构建审核提示词
        $messages = $this->buildAuditMessages($commentText);
        
        // 调用API
        $response = $this->callAPI($messages);
        
        // 解析响应
        return $this->parseAuditResponse($response);
    }

    /**
     * 构建审核消息
     */
    private function buildAuditMessages($commentText)
    {
        $messages = array();
        
        // 系统提示词 - 审核专用
        $systemPrompt = "你是一个内容审核助手，负责判断评论是否符合社区规范。\n\n"
                     . "审核标准：\n"
                     . "1. 不包含政治敏感内容\n"
                     . "2. 不包含暴力、恐怖内容\n"
                     . "3. 不包含色情、低俗内容\n"
                     . "4. 不包含赌博、违法内容\n"
                     . "5. 不包含人身攻击、侮辱性内容\n"
                     . "6. 不包含垃圾广告、诈骗信息\n\n"
                     . "请对以下评论进行审核，并返回：\n"
                     . "- 审核结果（通过/不通过）\n"
                     . "- 置信度（0-1之间）\n"
                     . "- 审核理由\n\n"
                     . "输出格式：\n"
                     . "{\"passed\": true/false, \"confidence\": 0.9, \"reason\": \"审核理由\"}";
        
        $messages[] = array(
            'role' => 'system',
            'content' => $systemPrompt
        );

        // 用户消息 - 待审核的评论
        $messages[] = array(
            'role' => 'user',
            'content' => "待审核评论：\n{$commentText}"
        );

        return $messages;
    }

    /**
     * 调用API
     */
    private function callAPI($messages)
    {
        $url = $this->apiEndpoint . '/chat/completions';
        
        // 构建请求体
        $requestBody = array(
            'model' => $this->modelName,
            'messages' => $messages,
            'temperature' => 0.1, // 审核任务使用较低温度
            'max_tokens' => 200,
            'stream' => false
        );

        // 构建请求头
        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        );

        // 阿里云特殊处理
        if ($this->provider == 'aliyun') {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            );
        }

        // 发送请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 生产环境建议启用
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('CURL请求失败: ' . $error);
        }

        if ($httpCode !== 200) {
            $errorInfo = json_decode($response, true);
            $errorMessage = isset($errorInfo['error']['message']) 
                ? $errorInfo['error']['message'] 
                : '未知错误';
            throw new Exception("API请求失败 (HTTP {$httpCode}): {$errorMessage}");
        }

        return $response;
    }

    /**
     * 解析审核响应
     */
    private function parseAuditResponse($response)
    {
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON解析失败: ' . json_last_error_msg());
        }

        // 提取AI回复内容
        $aiResponse = '';
        if (isset($data['choices'][0]['message']['content'])) {
            $aiResponse = trim($data['choices'][0]['message']['content']);
        } elseif (isset($data['output']['text'])) {
            $aiResponse = trim($data['output']['text']);
        } elseif (isset($data['result'])) {
            $aiResponse = trim($data['result']);
        } else {
            throw new Exception('无法从响应中提取审核结果');
        }

        // 尝试从AI回复中提取JSON格式的审核结果
        $match = array();
        if (preg_match('/\{[^}]*"passed"[^}]*\}/', $aiResponse, $match)) {
            $auditResult = json_decode($match[0], true);
            if (isset($auditResult['passed']) && isset($auditResult['confidence'])) {
                return array(
                    'passed' => (bool)$auditResult['passed'],
                    'confidence' => floatval($auditResult['confidence']),
                    'reason' => isset($auditResult['reason']) && !empty($auditResult['reason']) ? $auditResult['reason'] : ($auditResult['passed'] ? '审核通过' : '审核未通过')
                );
            }
        }

        // 如果AI没有返回JSON格式，基于内容判断
        $lowerResponse = strtolower($aiResponse);
        if (strpos($lowerResponse, '通过') !== false || strpos($lowerResponse, 'approved') !== false) {
            return array(
                'passed' => true,
                'confidence' => 0.9,
                'reason' => '审核通过'
            );
        } else {
            // 确保reason不为空
            $reason = !empty($aiResponse) ? '审核未通过: ' . $aiResponse : '审核未通过: 内容不符合规范';
            return array(
                'passed' => false,
                'confidence' => 0.9,
                'reason' => $reason
            );
        }
    }

    /**
     * 测试审核服务连接
     * 
     * @return array ['success' => bool, 'message' => string, 'result' => array]
     */
    public function testConnection()
    {
        try {
            $testComment = '这是一条测试评论，内容正常，没有敏感信息。';
            $result = $this->auditComment($testComment);
            
            return array(
                'success' => true,
                'message' => 'AI审核服务连接成功！',
                'result' => $result
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'AI审核服务连接失败: ' . $e->getMessage(),
                'result' => array()
            );
        }
    }
}
