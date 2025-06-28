This EDA rulebook that posts back to my podman container lkui-app is getting a 404   
   
    - name: Post failure to order endpoint
      ansible.builtin.uri:
        url: "http://lkui-app:8080/lkui/api/orders/{{ order_id }}/certificate"
        method: POST
        headers:
          Content-Type: "application/json"
        body_format: json
        body:
          status: "error"
          message: "{{ certbot_result.stderr | default('Unknown certbot error') }}"
      when: (certbot_result | default({})).get('rc', 1) != 0





TASK [Post failure to order endpoint] ******************************************
�fatal: [localhost]: FAILED! => {"changed": false, "connection": "close", "content_length": "21", "content_type": "text/html; charset=UTF-8", "date": "Sat, 28 Jun 2025 02:03:22 GMT", "elapsed": 0, "msg": "Status code was 404 and not [200]: HTTP Error 404: Not Found", "redirected": false, "server": "Apache/2.4.62 (Debian)", "status": 404, "url": "http://lkui-app:8080/lkui/api/orders/ssl_12345/certificate", "x_powered_by": "PHP/8.2.27"}


Give me a curl to test POST from cli to 

http://lkui-app:8080/lkui/api/orders/ssl_12345/certificate


    ['POST', '/lkui/api/orders/{orderId:\d+}/certificate', [$OrderCtrl, 'updateOrder']],


curl -i -X POST http://localhost:8080/lkui/api/orders/1/certificate \
  -H "Content-Type: application/json" \
  -d '{"status":"error","message":"Test certbot failure"}'
HTTP/1.1 404 Not Found
Date: Sat, 28 Jun 2025 02:19:44 GMT
Server: Apache/2.4.62 (Debian)
X-Powered-By: PHP/8.2.27
Content-Length: 21
Content-Type: text/html; charset=UTF-8

{"error":"Not Found"}% 


curl -i -X POST http://localhost:8080/lkui/api/orders/1/certificate \
  -H "Content-Type: application/json" \
  -d '{"status":"error","message":"Test certbot failure"}'
  Error: Unknown named parameter $orderId in file /var/www/src/Bootstrap.php on line 193
Stack trace:
  1. Error-&gt;() /var/www/src/Bootstrap.php:193
  2. call_user_func_array() /var/www/src/Bootstrap.php:193
  3. require() /var/www/public/index.php:1


curl -i -X POST http://localhost:8080/lkui/api/orders/1/certificate \
  -H "Content-Type: application/json" \
  -d '{"status":"error","message":"Test certbot failure"}'
HTTP/1.1 400 Bad Request
Date: Sat, 28 Jun 2025 05:19:50 GMT
Server: Apache/2.4.62 (Debian)
X-Powered-By: PHP/8.2.27
Content-Length: 55
Connection: close
Content-Type: application/json

{"status":"error","message":"cert_content is required"}%   