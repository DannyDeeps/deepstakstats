const axios = require('axios');

axios.get('https://ptero.deepstak.uk/api/application/servers', {
  headers: {
    Authorization: 'Bearer {{PTER_API_KEY}}'
  }
})
.then(function (response) {
  console.log(response.data);
})
.catch(function (error) {
  console.log(error);
});

