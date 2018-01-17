<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Install URL</title>
        <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
</head>
<body>
    <div class="container">
    <div class=" jumbotron text-center">
        <div class="row">
            <div class="col-lg-6 col-md-offset-3">
                <h1>Install App</h1>
                <form method="POST" action="/install">
                    {{ csrf_field() }}
                    <div class="input-group">
                        <input placeholder="Shop Domain" class="form-control" type="text" name="myshopify_domain" id="myshopify_domain">
                        <span class="input-group-btn">
                        <input class="btn btn-success form-input" type="submit" value="Install">
                        </span>
                    </div><!-- /input-group -->
                </form>
            </div><!-- /.col-lg-6 -->
        </form>
    </div>
    </div>
</body>
</html>