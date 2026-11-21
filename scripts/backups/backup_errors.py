class Failure(Exception):
    """Only this allowlisted code may cross process/callback boundaries."""

    def __init__(self, code):
        self.code = code
